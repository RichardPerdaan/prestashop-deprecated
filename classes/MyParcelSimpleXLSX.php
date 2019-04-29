<?php
/**
 *    SimpleXLSX php class v0.8.7
 *    MS Excel 2007 workbooks reader
 *
 * Copyright (c) 2012 - 2019 SimpleXLSX
 *
 * @category   SimpleXLSX
 * @package    SimpleXLSX
 * @copyright  Copyright (c) 2012 - 2019 SimpleXLSX (https://github.com/shuchkin/simplexlsx/)
 * @license    MIT
 * @version    0.8.7
 */

/** @noinspection PhpUndefinedFieldInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

class MyParcelSimpleXLSX {
    // Don't remove this string! Created by Sergey Shuchkin sergey.shuchkin@gmail.com
    const SCHEMA_REL_OFFICEDOCUMENT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';
    const SCHEMA_REL_SHAREDSTRINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings';
    const SCHEMA_REL_WORKSHEET = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet';
    const SCHEMA_REL_STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';
    public static $CF = array( // Cell formats
                               0  => 'General',
                               1  => '0',
                               2  => '0.00',
                               3  => '#,##0',
                               4  => '#,##0.00',
                               9  => '0%',
                               10 => '0.00%',
                               11 => '0.00E+00',
                               12 => '# ?/?',
                               13 => '# ??/??',
                               14 => 'mm-dd-yy',
                               15 => 'd-mmm-yy',
                               16 => 'd-mmm',
                               17 => 'mmm-yy',
                               18 => 'h:mm AM/PM',
                               19 => 'h:mm:ss AM/PM',
                               20 => 'h:mm',
                               21 => 'h:mm:ss',
                               22 => 'm/d/yy h:mm',

                               37 => '#,##0 ;(#,##0)',
                               38 => '#,##0 ;[Red](#,##0)',
                               39 => '#,##0.00;(#,##0.00)',
                               40 => '#,##0.00;[Red](#,##0.00)',

                               44 => '_("$"* #,##0.00_);_("$"* \(#,##0.00\);_("$"* "-"??_);_(@_)',
                               45 => 'mm:ss',
                               46 => '[h]:mm:ss',
                               47 => 'mmss.0',
                               48 => '##0.0E+0',
                               49 => '@',

                               27 => '[$-404]e/m/d',
                               30 => 'm/d/yy',
                               36 => '[$-404]e/m/d',
                               50 => '[$-404]e/m/d',
                               57 => '[$-404]e/m/d',

                               59 => 't0',
                               60 => 't0.00',
                               61 => 't#,##0',
                               62 => 't#,##0.00',
                               67 => 't0%',
                               68 => 't0.00%',
                               69 => 't# ?/?',
                               70 => 't# ??/??',
    );
    public $cellFormats = array();
    public $datetimeFormat = 'Y-m-d H:i:s';
    /* @var SimpleXMLElement $workbook */
    private $workbook;
    /* @var SimpleXMLElement[] $sheets */
    private $sheets = array();
    private $sheetNames = array();
    // scheme
    private $styles;
    private $hyperlinks;
    /* @var array[] $package */
    private $package;
    private $datasec;
    private $sharedstrings;
    private $date1904 = 0;
    private $errno = 0;
    private $error = false;
    private $debug;

    public function __construct( $filename, $is_data = false, $debug = false ) {
        $this->debug   = $debug;
        $this->package = array(
            'filename' => '',
            'mtime'    => 0,
            'size'     => 0,
            'comment'  => '',
            'entries'  => array()
        );
        if ( $this->_unzip( $filename, $is_data ) ) {
            $this->_parse();
        }
    }

    private function _unzip( $filename, $is_data = false ) {

        // Clear current file
        $this->datasec = array();

        if ( $is_data ) {

            $this->package['filename'] = 'default.xlsx';
            $this->package['mtime']    = time();
            $this->package['size']     = $this->_strlen( $filename );

            $vZ = $filename;
        } else {

            if ( ! is_readable( $filename ) ) {
                $this->error( 1, 'File not found ' . $filename );

                return false;
            }

            // Package information
            $this->package['filename'] = $filename;
            $this->package['mtime']    = filemtime( $filename );
            $this->package['size']     = filesize( $filename );

            // Read file
            $vZ = file_get_contents( $filename );
        }
        // Cut end of central directory
        /*		$aE = explode("\x50\x4b\x05\x06", $vZ);

                if (count($aE) == 1) {
                    $this->error('Unknown format');
                    return false;
                }
        */
        if ( ( $pcd = $this->_strrpos( $vZ, "\x50\x4b\x05\x06" ) ) === false ) {
            $this->error( 2, 'Unknown archive format' );

            return false;
        }
        $aE = array(
            0 => $this->_substr( $vZ, 0, $pcd ),
            1 => $this->_substr( $vZ, $pcd + 3 )
        );

        // Normal way
        $aP                       = unpack( 'x16/v1CL', $aE[1] );
        $this->package['comment'] = $this->_substr( $aE[1], 18, $aP['CL'] );

        // Translates end of line from other operating systems
        $this->package['comment'] = strtr( $this->package['comment'], array( "\r\n" => "\n", "\r" => "\n" ) );

        // Cut the entries from the central directory
        $aE = explode( "\x50\x4b\x01\x02", $vZ );
        // Explode to each part
        $aE = explode( "\x50\x4b\x03\x04", $aE[0] );
        // Shift out spanning signature or empty entry
        array_shift( $aE );

        // Loop through the entries
        foreach ( $aE as $vZ ) {
            $aI       = array();
            $aI['E']  = 0;
            $aI['EM'] = '';
            // Retrieving local file header information
            $aP = unpack( 'v1VN/v1GPF/v1CM/v1FT/v1FD/V1CRC/V1CS/V1UCS/v1FNL/v1EFL', $vZ );

            // Check if data is encrypted
            $bE = false;
            $nF = $aP['FNL'];
            $mF = $aP['EFL'];

            // Special case : value block after the compressed data
            if ( $aP['GPF'] & 0x0008 ) {
                $aP1 = unpack( 'V1CRC/V1CS/V1UCS', $this->_substr( $vZ, - 12 ) );

                $aP['CRC'] = $aP1['CRC'];
                $aP['CS']  = $aP1['CS'];
                $aP['UCS'] = $aP1['UCS'];
                // 2013-08-10
                $vZ = $this->_substr( $vZ, 0, - 12 );
                if ( $this->_substr( $vZ, - 4 ) === "\x50\x4b\x07\x08" ) {
                    $vZ = $this->_substr( $vZ, 0, - 4 );
                }
            }

            // Getting stored filename
            $aI['N'] = $this->_substr( $vZ, 26, $nF );

            if ( $this->_substr( $aI['N'], - 1 ) === '/' ) {
                // is a directory entry - will be skipped
                continue;
            }

            // Truncate full filename in path and filename
            $aI['P'] = dirname( $aI['N'] );
            $aI['P'] = $aI['P'] === '.' ? '' : $aI['P'];
            $aI['N'] = basename( $aI['N'] );

            $vZ = $this->_substr( $vZ, 26 + $nF + $mF );

            if ( $this->_strlen( $vZ ) !== (int) $aP['CS'] ) { // check only if availabled
                $aI['E']  = 1;
                $aI['EM'] = 'Compressed size is not equal with the value in header information.';
            } else if ( $bE ) {
                $aI['E']  = 5;
                $aI['EM'] = 'File is encrypted, which is not supported from this class.';
            } else {
                switch ( $aP['CM'] ) {
                    case 0: // Stored
                        // Here is nothing to do, the file ist flat.
                        break;
                    case 8: // Deflated
                        $vZ = gzinflate( $vZ );
                        break;
                    case 12: // BZIP2
                        if ( extension_loaded( 'bz2' ) ) {
                            /** @noinspection PhpComposerExtensionStubsInspection */
                            $vZ = bzdecompress( $vZ );
                        } else {
                            $aI['E']  = 7;
                            $aI['EM'] = 'PHP BZIP2 extension not available.';
                        }
                        break;
                    default:
                        $aI['E']  = 6;
                        $aI['EM'] = "De-/Compression method {$aP['CM']} is not supported.";
                }
                if ( ! $aI['E'] ) {
                    if ( $vZ === false ) {
                        $aI['E']  = 2;
                        $aI['EM'] = 'Decompression of data failed.';
                    } else if ( $this->_strlen( $vZ ) !== (int) $aP['UCS'] ) {
                        $aI['E']  = 3;
                        $aI['EM'] = 'Uncompressed size is not equal with the value in header information.';
                    } else if ( crc32( $vZ ) !== $aP['CRC'] ) {
                        $aI['E']  = 4;
                        $aI['EM'] = 'CRC32 checksum is not equal with the value in header information.';
                    }
                }
            }

            $aI['D'] = $vZ;

            // DOS to UNIX timestamp
            $aI['T'] = mktime( ( $aP['FT'] & 0xf800 ) >> 11,
                ( $aP['FT'] & 0x07e0 ) >> 5,
                ( $aP['FT'] & 0x001f ) << 1,
                ( $aP['FD'] & 0x01e0 ) >> 5,
                $aP['FD'] & 0x001f,
                ( ( $aP['FD'] & 0xfe00 ) >> 9 ) + 1980 );

            //$this->Entries[] = &new SimpleUnzipEntry($aI);
            $this->package['entries'][] = array(
                'data'      => $aI['D'],
                'error'     => $aI['E'],
                'error_msg' => $aI['EM'],
                'name'      => $aI['N'],
                'path'      => $aI['P'],
                'time'      => $aI['T']
            );

        } // end for each entries

        return true;
    }

    // sheets numeration: 1,2,3....

    public function error( $num = null, $str = null ) {
        if ( $num ) {
            $this->errno = $num;
            $this->error = $str;
            if ( $this->debug ) {
                trigger_error( __CLASS__ . ': ' . $this->error, E_USER_WARNING );
            }
        }

        return $this->error;
    }
    public function errno() {
        return $this->errno;
    }

    private function _parse() {
        // Document data holders
        $this->sharedstrings = array();
        $this->sheets        = array();

        // Read relations and search for officeDocument
        if ( $relations = $this->getEntryXML( '_rels/.rels' ) ) {

            foreach ( $relations->Relationship as $rel ) {

                $rel_type = trim( (string) $rel['Type'] );
                $rel_target = trim( (string) $rel['Target'] );

                if ( $rel_type === self::SCHEMA_REL_OFFICEDOCUMENT && $this->workbook = $this->getEntryXML( $rel_target ) ) {

                    $index_rId = array(); // [0 => rId1]

                    $index = 0;
                    foreach ( $this->workbook->sheets->sheet as $s ) {
                        /* @var SimpleXMLElement $s */
                        $this->sheetNames[ $index ] = (string) $s['name'];
                        $index_rId[ $index ] = (string) $s['id'];
                        $index++;
                    }
                    if ( (int) $this->workbook->workbookPr['date1904'] === 1 ) {
                        $this->date1904 = 1;
                    }

                    if ( $workbookRelations = $this->getEntryXML( dirname( $rel_target ) . '/_rels/workbook.xml.rels' ) ) {

                        // Loop relations for workbook and extract sheets...
                        foreach ( $workbookRelations->Relationship as $workbookRelation ) {

                            $wrel_type = trim( (string) $workbookRelation['Type'] );
                            $wrel_path = dirname( trim( (string) $rel['Target'] ) ) . '/' . trim( (string) $workbookRelation['Target'] );
                            if ( ! $this->entryExists( $wrel_path ) ) {
                                continue;
                            }


                            if ( $wrel_type === self::SCHEMA_REL_WORKSHEET ) { // Sheets

                                if ( $sheet = $this->getEntryXML( $wrel_path ) ) {
                                    $index = array_search( (string) $workbookRelation['Id'], $index_rId, false );
                                    $this->sheets[ $index ] = $sheet;
                                }

                            } else if ( $wrel_type === self::SCHEMA_REL_SHAREDSTRINGS ) {

                                if ( $sharedStrings = $this->getEntryXML( $wrel_path ) ) {
                                    foreach ( $sharedStrings->si as $val ) {
                                        if ( isset( $val->t ) ) {
                                            $this->sharedstrings[] = (string) $val->t;
                                        } elseif ( isset( $val->r ) ) {
                                            $this->sharedstrings[] = $this->_parseRichText( $val );
                                        }
                                    }
                                }
                            } else if ( $wrel_type === self::SCHEMA_REL_STYLES ) {

                                $this->styles = $this->getEntryXML( $wrel_path );

                                $nf = array();
                                if ( $this->styles->numFmts->numFmt !== null ) {
                                    foreach ( $this->styles->numFmts->numFmt as $v ) {
                                        $nf[ (int) $v['numFmtId'] ] = (string) $v['formatCode'];
                                    }
                                }

                                if ( $this->styles->cellXfs->xf !== null ) {
                                    foreach ( $this->styles->cellXfs->xf as $v ) {
                                        $v           = (array) $v->attributes();
                                        $v['format'] = '';

                                        if ( isset( $v['@attributes']['numFmtId'] ) ) {
                                            $v = $v['@attributes'];
                                            $fid = (int) $v['numFmtId'];
                                            if ( isset( self::$CF[ $fid ] ) ) {
                                                $v['format'] = self::$CF[ $fid ];
                                            } else if ( isset( $nf[ $fid ] ) ) {
                                                $v['format'] = $nf[ $fid ];
                                            }
                                        }
                                        $this->cellFormats[] = $v;
                                    }
                                }
                            }
                        }

                        break;
                    }
                }
            }
        }
        if ( count( $this->sheets ) ) {
            // Sort sheets
            ksort( $this->sheets );

            return true;
        }

        return false;
    }
    /*
     * @param string $name Filename in archive
     * @return SimpleXMLElement|bool
    */
    public function getEntryXML( $name ) {
        if ( $entry_xml = $this->getEntryData( $name ) ) {
            // dirty remove namespace prefixes
            $entry_xml = preg_replace('/xmlns[^=]*="[^"]*"/i','', $entry_xml ); // remove namespaces
            $entry_xml = preg_replace('/[a-zA-Z0-9]+:([a-zA-Z0-9]+="[^"]+")/','$1$2', $entry_xml ); // remove namespaced attrs
            $entry_xml = preg_replace('/<[a-zA-Z0-9]+:([^>]+)>/', '<$1>', $entry_xml); // fix namespaced openned tags
            $entry_xml = preg_replace('/<\/[a-zA-Z0-9]+:([^>]+)>/', '</$1>', $entry_xml); // fix namespaced closed tags

//			echo '<pre>'.$name."\r\n".htmlspecialchars( $entry_xml ).'</pre>'.

            // XML External Entity (XXE) Prevention
            $_old         = libxml_disable_entity_loader();
            $entry_xmlobj = simplexml_load_string( $entry_xml );
//			echo '<pre>'.print_r( $entry_xmlobj, true).'</pre>';
            libxml_disable_entity_loader($_old);
            if ( $entry_xmlobj ) {
                return $entry_xmlobj;
            }
            $e = libxml_get_last_error();
            $this->error( 3, 'XML-entry ' . $name.' parser error '.$e->message.' line '.$e->line );
        } else {
            $this->error( 4, 'XML-entry not found ' . $name );
        }
        return false;
    }

    public function getEntryData( $name ) {
        $dir  = $this->_strtoupper( dirname( $name ) );
        $name = $this->_strtoupper( basename( $name ) );
        foreach ( $this->package['entries'] as $entry ) {
            if ( $this->_strtoupper( $entry['path'] ) === $dir && $this->_strtoupper( $entry['name'] ) === $name ) {
                return $entry['data'];
            }
        }
        $this->error( 5, 'Entry not found '.$name );

        return false;
    }

    public function entryExists( $name ) { // 0.6.6
        $dir  = $this->_strtoupper( dirname( $name ) );
        $name = $this->_strtoupper( basename( $name ) );
        foreach ( $this->package['entries'] as $entry ) {
            if ( $this->_strtoupper( $entry['path'] ) === $dir && $this->_strtoupper( $entry['name'] ) === $name ) {
                return true;
            }
        }

        return false;
    }

    private function _parseRichText( $is = null ) {
        $value = array();

        if ( isset( $is->t ) ) {
            $value[] = (string) $is->t;
        } else if ( isset($is->r ) ) {
            foreach ( $is->r as $run ) {
                $value[] = (string) $run->t;
            }
        }

        return implode( ' ', $value );
    }

    public static function parse( $filename, $is_data = false, $debug = false ) {
        $xlsx = new self( $filename, $is_data, $debug );
        if ( $xlsx->success() ) {
            return $xlsx;
        }
        self::parseError( $xlsx->error() );
        self::parseErrno( $xlsx->errno() );

        return false;
    }
    public static function parseError( $set = false ) {
        static $error = false;
        return $set ? $error = $set : $error;
    }
    public static function parseErrno( $set = false ) {
        static $errno = false;
        return $set ? $errno = $set : $errno;
    }

    public function success() {
        return ! $this->error;
    }

    public function rows( $worksheetIndex = 0 ) {

        if ( ( $ws = $this->worksheet( $worksheetIndex ) ) === false ) {
            return false;
        }
        list( $numCols, $numRows) = $this->dimension( $worksheetIndex );

        $emptyRow = array();
        for( $i = 0; $i < $numCols; $i++) {
            $emptyRow[] = '';
        }

        $rows = array();
        for( $i = 0; $i < $numRows; $i++) {
            $rows[] = $emptyRow;
        }

        $curR = 0;
        /* @var SimpleXMLElement $ws */
        foreach ( $ws->sheetData->row as $row ) {
            $curC = 0;
            foreach ( $row->c as $c ) {
                // detect skipped cols
                list( $x, $y ) = $this->getIndex( (string) $c['r'] );
                if ( $x > -1 ) {
                    $curC = $x;
                    $curR = $y;
                }

                $rows[ $curR ][ $curC ] = $this->value( $c );
                $curC++;
            }

            $curR ++;
        }

        return $rows;
    }

    public function worksheet( $worksheetIndex = 0 ) {


        if ( isset( $this->sheets[ $worksheetIndex ] ) ) {
            $ws = $this->sheets[ $worksheetIndex ];

            if ( isset( $ws->hyperlinks ) ) {
                $this->hyperlinks = array();
                foreach ( $ws->hyperlinks->hyperlink as $hyperlink ) {
                    $this->hyperlinks[ (string) $hyperlink['ref'] ] = (string) $hyperlink['display'];
                }
            }

            return $ws;
        }
        $this->error( 6, 'Worksheet not found ' . $worksheetIndex );

        return false;
    }

    /**
     * returns [numCols,numRows] of worksheet
     *
     * @param int $worksheetIndex
     *
     * @return array
     */
    public function dimension( $worksheetIndex = 0 ) {

        if ( ( $ws = $this->worksheet( $worksheetIndex ) ) === false ) {
            return array(0,0);
        }
        /* @var SimpleXMLElement $ws */

        $ref = (string) $ws->dimension['ref'];

        if ( $this->_strpos( $ref, ':' ) !== false ) {
            $d = explode( ':', $ref );
            $index = $this->getIndex( $d[1] );

            return array( $index[0] + 1, $index[1] + 1 );
        }
        if ( $ref !== '' ) { // 0.6.8
            $index = $this->getIndex( $ref );

            return array( $index[0] + 1, $index[1] + 1 );
        }

        // slow method
        $maxC = $maxR = 0;
        foreach ( $ws->sheetData->row as $row ) {
            foreach ( $row->c as $c ) {
                list( $x, $y ) = $this->getIndex( (string) $c['r'] );
                if ( $x > 0 ) {
                    if ( $x > $maxC ) {
                        $maxC = $x;
                    }
                    if ( $y > $maxR ) {
                        $maxR = $y;
                    }
                }
            }
        }

        return array( $maxC+1, $maxR+1 );
    }

    public function getIndex( $cell = 'A1' ) {

        if ( preg_match( '/([A-Z]+)(\d+)/', $cell, $m ) ) {
            list( ,$col, $row ) = $m;

            $colLen = $this->_strlen( $col );
            $index  = 0;

            for ( $i = $colLen - 1; $i >= 0; $i -- ) {
                /** @noinspection PowerOperatorCanBeUsedInspection */
                $index += ( ord( $col{$i} ) - 64 ) * pow( 26, $colLen - $i - 1 );
            }

            return array( $index - 1, $row - 1 );
        }

        return array(-1,-1);
    }

    public function value( $cell ) {
        // Determine data type
        $dataType = (string) $cell['t'];

        if ( !$dataType ) { // number
            $s = (int) $cell['s'];
            if ( $s > 0 && isset( $this->cellFormats[ $s ] ) ) {
                $format = $this->cellFormats[ $s ]['format'];
                if ( strpos( $format, 'm') !== false ) {
                    $dataType = 'd';
                }
            }
        }

        $value = '';

        switch ( $dataType ) {
            case 's':
                // Value is a shared string
                if ( (string) $cell->v !== '' ) {
                    $value = $this->sharedstrings[ (int) $cell->v ];
                }

                break;

            case 'b':
                // Value is boolean
                $value = (string) $cell->v;
                if ( $value === '0' ) {
                    $value = false;
                } else if ( $value === '1' ) {
                    $value = true;
                } else {
                    $value = (bool) $cell->v;
                }

                break;

            case 'inlineStr':
                // Value is rich text inline
                $value = $this->_parseRichText( $cell->is );

                break;

            case 'e':
                // Value is an error message
                if ( (string) $cell->v !== '' ) {
                    $value = (string) $cell->v;
                }

                break;
            case 'd':
                // Value is a date
                $value = $this->datetimeFormat ? gmdate( $this->datetimeFormat, $this->unixstamp( (float) $cell->v ) ) : (float) $cell->v;
                break;


            default:
                // Value is a string
                $value = (string) $cell->v;

                // Check for numeric values
                if ( is_numeric( $value ) && $dataType !== 's' ) {
                    /** @noinspection TypeUnsafeComparisonInspection */
                    if ( $value == (int) $value ) {
                        $value = (int) $value;
                    } /** @noinspection TypeUnsafeComparisonInspection */ elseif ( $value == (float) $value ) {
                        $value = (float) $value;
                    }
                }
        }

        return $value;
    }

    public function unixstamp( $excelDateTime ) {

        $d = floor( $excelDateTime ); // days since 1900 or 1904
        $t = $excelDateTime - $d;

        if ( $this->date1904 ) {
            /** @noinspection SummerTimeUnsafeTimeManipulationInspection */
            $d += 1462;
        }


        /** @noinspection SummerTimeUnsafeTimeManipulationInspection */
        $t = ( abs( $d ) > 0 ) ? ( $d - 25569 ) * 86400 + round( $t * 86400 ) : round( $t * 86400 );

        return (int) $t;
    }

    /**
     * Returns cell value
     * VERY SLOW! Use ->rows() or ->rowsEx()
     *
     * @param int $worksheetIndex
     * @param string|array $cell ref or coords, D12 or [3,12]
     *
     * @return mixed Returns NULL if not found
     */
    public function getCell( $worksheetIndex = 0, $cell = 'A1' ) {

        if (($ws = $this->worksheet( $worksheetIndex)) === false) { return false; }

        list( $C, $R ) = is_array( $cell ) ? $cell : $this->getIndex( (string) $cell );

        $curR = 0;
        /* @var SimpleXMLElement $ws */
        foreach ( $ws->sheetData->row as $row ) {
            $curC = 0;
            foreach ( $row->c as $c ) {
                // detect skipped cols
                list( $x, $y ) = $this->getIndex( (string) $c['r'] );
                if ( $x > 0 ) {
                    $curC = $x;
                    $curR = $y;
                }
                if ( $curR === $R && $curC === $C ) {
                    return $this->value( $c );
                }
                if ( $curR > $R ){
                    return null;
                }
                $curC++;
            }

            $curR ++;
        }
        return null;
    }

    public function href( $cell ) {
        return isset( $this->hyperlinks[ (string) $cell['r'] ] ) ? $this->hyperlinks[ (string) $cell['r'] ] : '';
    }

    public function sheets() {
        return $this->sheets;
    }

    public function sheetsCount() {
        return count( $this->sheets );
    }

    public function sheetName( $worksheetIndex ) {
        if ( isset($this->sheetNames[ $worksheetIndex ])) {
            return $this->sheetNames[ $worksheetIndex ];
        }

        return false;
    }

    public function sheetNames() {

        return $this->sheetNames;
    }

    // thx Gonzo

    public function getStyles() {
        return $this->styles;
    }

    public function getPackage() {
        return $this->package;
    }
    public function setDateTimeFormat( $value ) {
        $this->datetimeFormat = is_string( $value) ? $value : false;
    }
    private function _strlen( $str ) {
        return (ini_get('mbstring.func_overload') & 2) ? mb_strlen($str , '8bit') : strlen($str);
    }
    private function _strpos( $haystack, $needle, $offset = 0 ) {
        return (ini_get('mbstring.func_overload') & 2) ? mb_strpos( $haystack, $needle, $offset , '8bit') : strpos($haystack, $needle, $offset);
    }
    private function _strrpos( $haystack, $needle, $offset = 0 ) {
        return (ini_get('mbstring.func_overload') & 2) ? mb_strrpos( $haystack, $needle, $offset, '8bit') : strrpos($haystack, $needle, $offset);
    }
    private function _strtoupper( $str ) {
        return (ini_get('mbstring.func_overload') & 2) ? mb_strtoupper($str , '8bit') : strtoupper($str);
    }
    private function _substr( $str, $start, $length = null ) {
        return (ini_get('mbstring.func_overload') & 2) ? mb_substr( $str, $start, ($length === null) ? mb_strlen($str,'8bit') : $length, '8bit') : substr($str, $start, ($length === null) ? strlen($str) : $length );
    }

}