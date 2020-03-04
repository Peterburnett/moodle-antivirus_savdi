<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Sophos SAVDI antivirus protocol client.
 *
 * @package    antivirus_savdi
 * @copyright  2017 The University of Southern Queensland
 * @author     Jonathon Fowler <fowlerj@usq.edu.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace antivirus_savdi;

defined('MOODLE_INTERNAL') || die();

use moodle_exception;

/**
 * SAVDI protocol client implementation.
 *
 * See https://www.sophos.com/en-us/medialibrary/PDFs/documentation/savi_sssp_13_meng.pdf
 * for the specification.
 *
 * @copyright  2017 The University of Southern Queensland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /**
     * The TCP/Unix socket.
     * @var resource
     */
    private $socket;

    /**
     * Whether to emit the SAVDI conversation as debug output.
     * @var boolean
     */
    public $debugprotocol = false;

    /**
     * Discovered viruses in the most recent scan.
     * @var array filename => virus name
     */
    private $viruses = [];

    /**
     * The most recent scanner daemon error message from the most recent scan.
     * @var string
     */
    private $errormsg;

    /**
     * A good scan result.
     * @var integer
     */
    const RESULT_OK = 0;

    /**
     * A virus was found result.
     * @var integer
     */
    const RESULT_VIRUS = 1;

    /**
     * A scan failure result.
     * @var integer
     */
    const RESULT_ERROR = 2;

    /**
     * Establish a connection to the SAVDI daemon or die trying.
     *
     * @param string $type 'unix' or 'tcp'
     * @param string $host path to unix socket, or tcp host:port
     * @return void
     * @throws moodle_exception
     */
    public function connect($type, $host) {
        $this->close();
        // If type is not unix, it must be either remote or local tcp.
        if ($type !== 'unix') {
            $conntype = 'tcp';
        }
        $this->socket = stream_socket_client($conntype . '://' . $host, $errno, $errstr, 5);
        if (!$this->socket) {
            throw new moodle_exception('errorcantopen'.$type.'socket', 'antivirus_savdi', '', "$errstr ($errno)");
        }

        // Expect the server to greet us.
        if ($this->getmessage() !== "OK SSSP/1.0") {
            fclose($this->socket);
            $this->socket = null;
            throw new moodle_exception('errorprotocol', 'antivirus_savdi', '', 'bad server greeting');
        }
        $this->sendmessage("SSSP/1.0");
        if (strpos($this->getmessage(), "ACC ") !== 0) {
            fclose($this->socket);
            $this->socket = null;
            throw new moodle_exception('errorprotocol', 'antivirus_savdi', '', 'bad protocol version handshake');
        }
    }

    /**
     * Disconnect from the SAVDI daemon in a clean manner.
     *
     * @return void
     */
    public function close() {
        if (!$this->socket) {
            return;
        }

        // Disconnect cleanly.
        $this->sendmessage("BYE");
        if ($this->getmessage() !== "BYE" && $this->debugprotocol) {
            debugging(get_string('warnprotocol', 'antivirus_savdi', 'did not receive expected signoff'), DEBUG_DEVELOPER);
        }

        fclose($this->socket);
        $this->socket = null;
    }

    /**
     * Scan a file.
     *
     * @param string $filename
     * @return integer RESULT_* codes
     */
    public function scanfile($filename) {
        return $this->scan('SCANFILE', $filename);
    }

    /**
     * Scan a directory.
     *
     * @param string $dirname
     * @param boolean $recurse
     * @return integer RESULT_* codes
     */
    public function scandir($dirname, $recurse = false) {
        if ($recurse) {
            $verb = 'SCANDIRR';
        } else {
            $verb = 'SCANDIR';
        }
        return $this->scan($verb, $dirname);
    }

    /**
     * Scan files and directories.
     *
     * @param string $cmd SCANFILE, SCANDIR, SCANDIRR
     * @param string $path
     * @return integer RESULT_* codes
     */
    private function scan($cmd, $path) {
        $scanresult = self::RESULT_ERROR;
        $expectnewline = false;
        $this->viruses = [];
        $this->errormsg = null;

        // If remotetcp is enabled, all requests must be converted to SCANDATA.
        if (get_config('antivirus_savdi', 'conntype') === 'remotetcp') {
            $command = $this->convert_path_to_scandata($cmd, $path);
            $this->sendmessage($command);
        } else {
            $this->sendmessage("$cmd " . urlencode($path));
        }

        while (true) {
            $msg = $this->getmessage();
            if ($msg === null) {
                break;  // EOF.
            } else if ($msg === "") {
                if ($expectnewline) {
                    break;  // Newline after a 'DONE'.
                }
                continue;
            }
            list($response, $extra) = explode(' ', $msg, 2);
            switch ($response) {
                case 'ACC':     // Daemon accepted the request.
                    continue;
                case 'REJ':     // Daemon rejected the request.
                    break 2;
                case 'EVENT':   // Progress reporting.
                case 'TYPE':
                case 'FILE':
                    continue;
                case 'OK':      // Outcome reporting.
                case 'FAIL':
                    continue;
                case 'VIRUS':   // Virus identified.
                    list ($virus, $filename) = explode(' ', $extra, 2);
                    $this->viruses[$filename] = $virus;
                    debugging('found virus ' . $virus . ' in ' . $filename, DEBUG_NORMAL);
                    continue;
                case 'DONE':
                    list ($result, $code, $codemsg) = explode(' ', $extra, 3);
                    if ($result === 'OK') {
                        if ($code === '0000') {
                            $scanresult = self::RESULT_OK;      // No virus.
                        } else if ($code === '0203') {
                            $scanresult = self::RESULT_VIRUS;   // Virus found.
                        } else {
                            debugging(get_string('warngeneral', 'antivirus_savdi', "OK - $codemsg ($code)"), DEBUG_NORMAL);
                        }
                    } else {
                        debugging(get_string('errorgeneral', 'antivirus_savdi', "FAIL - $codemsg ($code)"), DEBUG_NORMAL);
                    }
                    $this->errormsg = "$code $codemsg";
                    $expectnewline = true;
                    continue;
                default:
                    if ($this->debugprotocol) {
                        debugging(get_string('errorprotocol', 'antivirus_savdi',
                            "wasn't expecting: " . addcslashes($msg, "\0..\37!@\177..\377")), DEBUG_DEVELOPER);
                    }
                    break;
            }
        }

        return $scanresult;
    }

    /**
     * Read a message from the server.
     *
     * @return string|null message string, or null on error or eof
     */
    private function getmessage() {
        $msg = fgets($this->socket);
        if ($msg === false) {
            // Error or EOF.
            if ($this->debugprotocol) {
                debugging('SAVDI > (EOF)', DEBUG_DEVELOPER);
            }
            return null;
        }
        $msg = rtrim($msg, "\r\n");
        if ($this->debugprotocol) {
            debugging('SAVDI > '.$msg, DEBUG_DEVELOPER);
        }
        return $msg;
    }

    /**
     * Write a message to the server.
     *
     * @param string $msg
     * @return void
     */
    private function sendmessage($msg) {
        if ($this->debugprotocol) {
            debugging('SAVDI < '.$msg, DEBUG_DEVELOPER);
        }
        fwrite($this->socket, $msg . "\r\n");
        fflush($this->socket);
    }

    /**
     * Return the list of discovered viruses from the last scan.
     *
     * @return array filename => virus name
     */
    public function get_scan_viruses() {
        return $this->viruses;
    }

    /**
     * Return the scanner response from the last scan.
     *
     * @return string
     */
    public function get_scan_message() {
        return $this->errormsg;
    }

    /**
     * Take a scan command, and convert it into SCANDATA
     *
     * @param string $cmd the command to convert.
     * @param string $path the file or directory to convert.
     *
     * @return string command string of format SCANDATA <size> <data>
     */
    private function convert_path_to_scandata($cmd, $path) {
        $command = 'SCANDATA ';
        $dataarray = [];
        if ($cmd === 'SCANFILE') {
            $dataarray = $this->convert_file_to_scandata($path);
        } else {
            // A directory will need to be scanned.
            $recursive = $cmd === 'SCANDIRR' ? true : false;
            $dataarray = array_merge($dataarray, $this->convert_dir_to_scandata($path, $recursive));
        }

        // Now generate scandata command from data.
        $totalsize = 0;
        $datastring = '';
        foreach ($dataarray as $size => $datastream) {
            $totalsize += $size;
            $datastring .= $datastream;
        }

        return $command . $totalsize . PHP_EOL . $datastring;
    }

    /**
     * Reads in a file, and converts it to a keyed array of size => data.
     *
     * @param string $file the file to convert.
     *
     * @return array an array of size => data for a file.
     */
    private function convert_file_to_scandata($file) {
        $filehandle = fopen($file, "r");
        $streamsize = filesize($file);
        $datastream = fread($filehandle, $streamsize);
        return array($streamsize => $datastream);
    }

    /**
     * Converts a directory to scandata for remote use.
     * @param string $path the path to convert.
     * @param bool $recursive whether this should convert all directories recursively.
     *
     * @return array an array of size=>data for each file inside the directory.
     */
    private function convert_dir_to_scandata($path, $recursive) {
        $return = [];

        // Get all files, and all dirs seperately.
        $dirs = glob($path, '/*', GLOB_ONLYDIR);

        if ($recursive) {
            foreach ($dirs as $dir) {
                $return = array_merge($return, $this->convert_dir_to_scandata($dir, $recursive));
            }
        }

        // Scan all files in dir.
        $files = scandir($path);
        foreach ($files as $file) {
            if (is_file($file)) {
                $return = array_merge($return, $this->convert_file_to_scandata($file));
            }
        }

        return $return;
    }
}
