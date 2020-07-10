<?php

/*******************************************************************************
*                                                                              *
*   Asinius\IniFile\ExtendedIniFile                                            *
*                                                                              *
*   This class extends the ClassicIniFile class, replacing PHP's built-in      *
*   parse_ini_file() function with something that allows ini files to support  *
*   lists, proper parsing of true/false values, improved handling of quoted    *
*   strings, integers, "# comment lines", and more.                            *
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
*   \Asinius\IniFile\ExtendedIniFile                                           *
*                                                                              *
*******************************************************************************/

class ExtendedIniFile extends \Asinius\IniFile\ClassicIniFile
{

    protected $_lines = [];


    /**
     * Convert a string value from an ini line into an array, string, integer,
     * or boolean, depending on a few simple rules.
     *
     * @param   string      $value
     *
     * @internal
     *
     * @return  mixed
     */
    protected static function _unquote_and_typecast ($value)
    {
        $value = trim($value);
        $is_array = false;
        if ( strlen($value) > 1 && $value[0] == '[' && $value[strlen($value)-1] == ']' ) {
            //  Treat as an explicit array.
            $is_array = true;
            $value = substr($value, 1, -1);
        }
        $value = array_map('trim', \Asinius\Functions::str_chunk($value, ',', 0, \Asinius\Functions::DEFAULT_QUOTES));
        if ( $is_array || count($value) > 1 ) {
            $value = array_map(function($element){
                return forward_static_call([__CLASS__, '_unquote_and_typecast'], trim($element, ', '));
            }, $value);
            return $value;
        }
        $value = array_shift($value);
        $is_quoted = false;
        while ( strlen($value) > 0 && ($value[0] == '"' || $value[0] == "'") && $value[strlen($value)-1] == $value[0] ) {
            $value = substr($value, 1, -1);
            $is_quoted = true;
        }
        if ( $is_quoted ) {
            return $value;
        }
        //  If the value was unquoted and is empty, or was unquoted and is some
        //  variation of "null", treat it as a null value.
        if ( strlen($value) == 0 || in_array($value, ['null', 'Null', 'NULL']) ) {
            return null;
        }
        //  Interpret simple, unquoted true/false values as booleans.
        if ( in_array($value, ['true', 'false', 'True', 'False', 'TRUE', 'FALSE']) ) {
            return strcasecmp($value, 'true') == 0;
        }
        //  Interpret numeric values as ints or floats if possible.
        if ( is_numeric($value) ) {
            $value = floatval($value);
            if ( floor($value) == $value ) {
                $value = intval($value);
            }
            return $value;
        }
        //  Everything else is a string.
        return $value;
    }


    /**
     * Instantiate a new ExtendedIniFile object.
     *
     * @param   string      $file
     *
     * @return  \Asinius\IniFile\ClassicIniFile
     */
    public function __construct ($file)
    {
        $this->_filepath = $file;
        $file = @fopen($this->_assert_readable_file($file), 'r');
        if ( $file === false ) {
            throw new \RuntimeException('Failed to open file at ' . $this->_filepath);
        }
        $current_section = '';
        $section_values = new \Asinius\StrictArray();
        while ( ($line = @fgets($file)) !== false ) {
            $line = trim($line);
            $this->_lines[] = $line;
            if ( ($n = strlen($line)) == 0 || $line[0] == ';' || $line[0] == '#' ) {
                //  Comment or empty line.
                continue;
            }
            if ( $line[0] == '[' && $line[$n-1] == ']' ) {
                if ( count($section_values) > 0 ) {
                    $this->_store([$current_section], [$section_values]);
                }
                $current_section = substr($line, 1, -1);
                $section_values = new \Asinius\StrictArray();
                continue;
            }
            $line_parts = array_map('trim', \Asinius\Functions::str_chunk($line, '=', 2, \Asinius\Functions::DEFAULT_QUOTES));
            if ( count($line_parts) != 2  || $line_parts[1][0] != '=' ) {
                throw new \RuntimeException('Error parsing ' . $this->_filepath . ': No assignment operator (=) was found on line ' . count($this->_lines), EPARSE);
            }
            $line_parts[1] = trim(substr($line_parts[1], 1));
            list($keys, $value) = $line_parts;
            $keys = array_map(function($key){
                $key = trim($key);
                while ( strlen($key) > 0 && ($key[0] == '"' || $key[0] == "'") && $key[strlen($key)-1] == $key[0] ) {
                    $key = substr($key, 1, -1);
                }
                return $key;
            }, \Asinius\Functions::str_chunk($key, ',', 0, \Asinius\Functions::DEFAULT_QUOTES));
            $value = static::_unquote_and_typecast($value);
            foreach ($keys as $key) {
                if ( strlen($key) < 1 ) {
                    throw new \RuntimeException('Error parsing ' . $this->_filepath . ': An invalid key was found on line ' . count($this->_lines), EPARSE);
                }
                $section_values[$key] = $value;
            }
        }
        if ( count($section_values) > 0 ) {
            $this->_store([$current_section], [$section_values]);
        }
    }

}
