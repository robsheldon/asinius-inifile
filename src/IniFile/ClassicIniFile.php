<?php

/*******************************************************************************
*                                                                              *
*   Asinius\IniFile\ClassicIniFile                                             *
*                                                                              *
*   Loads an ini-style file into an Asinius StrictArray, using PHP's           *
*   built-in parse_ini_file(). See ExtendedIniFile for a more complete         *
*   implementation with some extra features. This implementation provides      *
*   a read-only abstraction for ini file data; values can be changed by the    *
*   application by default but they can't be written back out to the file.     *
*                                                                              *
*   LICENSE                                                                    *
*                                                                              *
*   Copyright (c) 2020 Rob Sheldon <rob@rescue.dev>                            *
*                                                                              *
*   Permission is hereby granted, free of charge, to any person obtaining a    *
*   copy of this software and associated documentation files (the "Software"), *
*   to deal in the Software without restriction, including without limitation  *
*   the rights to use, copy, modify, merge, publish, distribute, sublicense,   *
*   and/or sell copies of the Software, and to permit persons to whom the      *
*   Software is furnished to do so, subject to the following conditions:       *
*                                                                              *
*   The above copyright notice and this permission notice shall be included    *
*   in all copies or substantial portions of the Software.                     *
*                                                                              *
*   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS    *
*   OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF                 *
*   MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.     *
*   IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY       *
*   CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,       *
*   TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE          *
*   SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.                     *
*                                                                              *
*   https://opensource.org/licenses/MIT                                        *
*                                                                              *
*******************************************************************************/

namespace Asinius\IniFile;


/*******************************************************************************
*                                                                              *
*   \Asinius\IniFile\ClassicIniFile                                            *
*                                                                              *
*******************************************************************************/

class ClassicIniFile extends \Asinius\StrictArray
{

    protected $_filepath = '';


    /**
     * Inheritable convenience function for ensuring that the object has a
     * readable file before processing it. Returns the complete path to the
     * file if it's readable.
     *
     * @param   string      $path
     *
     * @internal
     *
     * @throws  \RuntimeException
     *
     * @return  string
     */
    protected function _assert_readable_file ($path)
    {
        $filepath = @realpath($path);
        if ( $filepath === false || ! @file_exists($filepath) ) {
            throw new \RuntimeException("INI file not found at $path", ENOENT);
        }
        if ( ! @is_readable($filepath) ) {
            throw new \RuntimeException("INI file at $path exists but is not readable.", EACCESS);
        }
        if ( ! @is_file($filepath) ) {
            throw new \RuntimeException("INI file at $path is not a text file.", EFTYPE);
        }
        return $filepath;
    }


    /**
     * Instantiate a new ClassicIniFile object.
     *
     * @param   string      $file
     *
     * @return  \Asinius\IniFile\ClassicIniFile
     */
    public function __construct ($file)
    {
        $this->_filepath = $file;
        $contents = parse_ini_file($this->_assert_readable_file($file), true);
        foreach ($contents as $section => $lines) {
            $values = new \Asinius\StrictArray();
            foreach ($lines as $keys => $value) {
                $keys = array_map('trim', explode(',', $keys));
                foreach ($keys as $setting) {
                    if ( is_numeric($value) ) {
                        $value = floatval($value);
                        if ( floor($value) == $value ) {
                            $value = intval($value);
                        }
                    }
                    else if ( is_array($value) && count($value) == 1 && is_string($value[0]) && strlen($value[0]) == 0 ) {
                        //  Fix handling of empty arrays from broken parse_ini_file() function.
                        $value = [];
                    }
                    $values[$setting] = $value;
                }
            }
            $this->_store([$section], [$values]);
        }
    }


    /**
     * Verify that the values loaded from an ini file match the expected types.
     *
     * If $throw is true (default), then verification errors will cause
     * exceptions to be thrown. Otherwise, the function will return a StrictArray
     * of the mismatches, or [] if everything passes verification.
     *
     * If $strict is true, then any values that are present in the ini file but
     * not in the list of defaults will also cause verification to fail.
     *
     * If $defaults is a string, it is treated as a path to an ini file.
     *
     * @param   mixed       $defaults
     * @param   boolean     $strict
     * @param   boolean     $throw
     *
     * @return  mixed
     */
    public function verify ($defaults, $strict = false, $throw = true)
    {
        if ( is_string($defaults) ) {
            //  Treat as a file path.
            $my_class = get_class($this);
            $defaults = new $my_class($defaults);
        }
        else if ( ! is_array($defaults) && ! is_a($defaults, '\Asinius\StrictArray') ) {
            throw new \RuntimeException("Not a valid value for ini file defaults", EINVAL);
        }
        $mismatches = new \Asinius\StrictArray();
        foreach ($this as $section => $values) {
            if ( $strict && ! isset($defaults[$section]) ) {
                if ( $throw ) {
                    throw new \RuntimeException("Error parsing {$this->_filepath}: found a [${section}] section, but this isn't defined in the expected defaults and strict checking is on", EPARSE);
                }
                $mismatches[$section] = $values;
                //  Skip any further verification of sections that are not
                //  pre-defined in the defaults.
                continue;
            }
            $section_mismatches = new \Asinius\StrictArray();
            foreach ($values as $key => $value) {
                if ( ! isset($defaults[$section][$key]) ) {
                    if ( $strict ) {
                        if ( $throw ) {
                            throw new \RuntimeException("Error parsing {$this->_filepath}: found a \"${key}\" value in the [${section}] section, but this isn't defined in the expected defaults and strict checking is on", EPARSE);
                        }
                        $section_mismatches[$key] = null;
                    }
                }
                else if ( (is_array($defaults[$section][$key]) && ! is_array($value)) || ( ! is_array($defaults[$section][$key]) && is_array($value)) ) {
                    if ( $throw ) {
                        //  Oops. Type mismatch.
                        throw new \RuntimeException("Error parsing {$this->_filepath}: expected a type of \"" . gettype($defaults[$section][$key]) . "\" for ${key} in the [${section}] section, but a type " . gettype($value) . " is there instead", EPARSE);
                    }
                    $section_mismatches[$key] = $value;
                }
            }
            if ( count($section_mismatches) > 0 ) {
                $mismatches[$section] = $section_mismatches;
            }
        }
        if ( count($mismatches) == 0 ) {
            return [];
        }
        return $mismatches;
    }


    /**
     * Return the original path that was used to create this object.
     *
     * @return  string
     */
    public function filepath ()
    {
        return $this->_filepath;
    }


    /**
     * Return a text representation of the values in the configuration file.
     * This is modeled after PHP's native __toString() output for arrays.
     * 
     * @return  string
     */
    public function print ()
    {
        $output = '';
        $output  = "INI file \"{$this->_filepath}\"";
        $output .= "\n(\n";
        foreach ($this as $section => $values) {
            $output .= "    [$section]\n";
            foreach ($values as $key => $value) {
                $value_type = gettype($value);
                switch ($value_type) {
                    case 'string':
                        $output .= "        $key => \"$value\"\n";
                        break;
                    default:
                        $output .= "        $key => " . \Asinius\Functions::to_str($value) . "\n";
                        break;
                }
            }
        }
        $output .= ")\n";
        return $output;
    }


    /**
     * Return the original path that was used to create this object when this
     * object is used in a string context.
     *
     * @return  string
     */
    public function __toString ()
    {
        return $this->_filepath;
    }


}
