<?php
/**
 * Copyright (C) 2012
 * Lars Strojny, InterNations GmbH <lars.strojny@internations.org>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace FXMLRPC\Parser;

use FXMLRPC\Value\Base64;
use XMLReader;
use RuntimeException;
use DateTime;
use DateTimeZone;

class XMLReaderParser implements ParserInterface
{
    public function __construct()
    {
        if (!extension_loaded('xmlreader')) {
            throw new RuntimeException('PHP extension ext/xmlreader missing');
        }
    }

    public function parse($xmlString, &$isFault)
    {
        $useErrors = libxml_use_internal_errors(true);

        $xml = new XMLReader();
        $xml->xml(
            $xmlString,
            'UTF-8',
            LIBXML_COMPACT | LIBXML_PARSEHUGE | LIBXML_NOCDATA | LIBXML_NOEMPTYTAG | LIBXML_NOBLANKS
        );
        $xml->setParserProperty(XMLReader::VALIDATE, false);
        $xml->setParserProperty(XMLReader::LOADDTD, false);

        $aggregates = array();
        $depth = 0;
        $nextElements = array('methodResponse' => true);
        while ($xml->read()) {
            $nodeType = $xml->nodeType;
            if ($nodeType === XMLReader::SIGNIFICANT_WHITESPACE && !isset($nextElements['#text'])) {
                continue;
            }

            $tagName = $xml->localName;
            if (!isset($nextElements[$tagName])) {
                throw new RuntimeException(
                    sprintf(
                        'Invalid XML. Expected one of "%s", got "%s" on depth %d (context: "%s")',
                        join('", "', array_keys($nextElements)),
                        $tagName,
                        $xml->depth,
                        $xml->readOuterXml()
                    )
                );
            }

            switch ($nodeType) {
                case XMLReader::ELEMENT:
                    switch ($tagName) {
                        case 'methodResponse':
                            $nextElements = array('params' => true, 'fault' => true);
                            break;

                        case 'fault':
                            $nextElements = array('value' => true);
                            $isFault = true;
                            break;

                        case 'params':
                            $nextElements = array('param' => true);
                            $aggregates[$depth] = array();
                            $isFault = false;
                            break;

                        case 'param':
                            $nextElements = array('value' => true);
                            break;

                        case 'array':
                            $nextElements = array('data' => true);
                            $aggregates[++$depth] = array();
                            break;

                        case 'data':
                            $nextElements = array('value' => true, 'data' => true);
                            break;

                        case 'struct':
                            $nextElements = array('member' => true);
                            $aggregates[++$depth] = array();
                            break;

                        case 'member':
                            $nextElements = array('name' => true, 'value' => true);
                            $aggregates[++$depth] = array();
                            break;

                        case 'name':
                            $nextElements = array('#text' => true);
                            $type = 'name';
                            break;

                        case 'value':
                            $nextElements = array(
                                'string'           => true,
                                'array'            => true,
                                'struct'           => true,
                                'int'              => true,
                                'biginteger'       => true,
                                'i8'               => true,
                                'i4'               => true,
                                'i2'               => true,
                                'i1'               => true,
                                'boolean'          => true,
                                'double'           => true,
                                'float'            => true,
                                'bigdecimal'       => true,
                                'dateTime.iso8601' => true,
                                'base64'           => true,
                                'nil'              => true,
                            );
                            break;

                        case 'base64':
                        case 'string':
                        case 'biginteger':
                        case 'i8':
                        case 'dateTime.iso8601':
                            $nextElements = array('#text' => true, $tagName => true, 'value' => true);
                            $type = $tagName;
                            $aggregates[$depth + 1] = '';
                            break;

                        case 'nil':
                            $nextElements = array($tagName => true, 'value' => true);
                            $type = $tagName;
                            $aggregates[$depth + 1] = null;
                            break;

                        case 'int':
                        case 'i4':
                        case 'i2':
                        case 'i1':
                            $nextElements = array('#text' => true, $tagName => true, 'value' => true);
                            $type = $tagName;
                            $aggregates[$depth + 1] = 0;
                            break;

                        case 'boolean':
                            $nextElements = array('#text' => true, $tagName => true, 'value' => true);
                            $type = $tagName;
                            $aggregates[$depth + 1] = false;
                            break;

                        case 'double':
                        case 'float':
                        case 'bigdecimal':
                            $nextElements = array('#text' => true, $tagName => true, 'value' => true);
                            $type = $tagName;
                            $aggregates[$depth + 1] = 0.0;
                            break;

                        default:
                            throw new RuntimeException(
                                sprintf(
                                    'Invalid tag <%s> found',
                                    $tagName
                                )
                            );
                    }
                    break;

                case XMLReader::END_ELEMENT:
                    switch ($tagName) {
                        case 'param':
                        case 'fault':
                            break 3;

                        case 'value':
                            $nextElements = array(
                                'param'  => true,
                                'value'  => true,
                                'data'   => true,
                                'member' => true,
                                'name'   => true,
                                'int'    => true,
                                'i4'     => true,
                                'i2'     => true,
                                'i1'     => true,
                                'base64' => true,
                                'fault'  => true,
                            );
                            $aggregates[$depth][] = $aggregates[$depth + 1];
                            break;

                        case 'string':
                        case 'int':
                        case 'biginteger':
                        case 'i8':
                        case 'i4':
                        case 'i2':
                        case 'i1':
                        case 'boolean':
                        case 'double':
                        case 'float':
                        case 'bigdecimal':
                        case 'dateTime.iso8601':
                        case 'base64':
                            $nextElements = array('value' => true);
                            break;

                        case 'data':
                            $nextElements = array('array' => true);
                            break;

                        case 'array':
                            $nextElements = array('value' => true);
                            --$depth;
                            break;

                        case 'name':
                            $nextElements = array('value' => true, 'member' => true);
                            $aggregates[$depth]['name'] = $aggregates[$depth + 1];
                            break;

                        case 'member':
                            $nextElements = array('struct' => true, 'member' => true);
                            $aggregates[$depth - 1][$aggregates[$depth]['name']] = $aggregates[$depth][0];
                            unset($aggregates[$depth], $aggregates[$depth + 1]);
                            --$depth;
                            break;

                        case 'struct':
                            $nextElements = array('value' => true);
                            --$depth;
                            break;

                        default:
                            throw new RuntimeException(
                                sprintf(
                                    'Invalid tag </%s> found',
                                    $tagName
                                )
                            );
                    }
                    break;

                case XMLReader::TEXT:
                case XMLReader::SIGNIFICANT_WHITESPACE:
                    switch ($type) {
                        case 'int':
                        case 'i4':
                        case 'i2':
                        case 'i1':
                            $value = (int) $xml->value;
                            break;

                        case 'boolean':
                            $value = $xml->value === '1';
                            break;

                        case 'double':
                        case 'float':
                        case 'bigdecimal':
                            $value = (double) $xml->value;
                            break;

                        case 'dateTime.iso8601':
                            $value = DateTime::createFromFormat('Ymd\TH:i:s', $xml->value, new DateTimeZone('UTC'));
                            break;

                        case 'base64':
                            $value = Base64::serialize($xml->value);
                            break;

                        default:
                            $value = $xml->value;
                            break;
                    }

                    $aggregates[$depth + 1] = $value;
                    $nextElements = array($type => true);
                    break;
            }
        }

        libxml_use_internal_errors($useErrors);

        return isset($aggregates[0][0]) ? $aggregates[0][0] : null;
    }
}
