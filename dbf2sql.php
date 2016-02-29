<?php 

require __DIR__ . '/vendor/autoload.php';

use XBase\Table;
use XBase\Record;
use Ulrichsg\Getopt\Getopt;
use Ulrichsg\Getopt\Option;

function usage($errormessage = "error") {
    global $argv;
    echo "\n$errormessage\n\n";
    echo "Usage: $argv[0] [-e encoding] source_file [another_source_file [...]]\n\n";
    echo "Default encoding is utf-8, often used encoding in dbf files is CP1250, or CP1251\n\n";
}

$encOption = new Option('e', 'encoding', Getopt::REQUIRED_ARGUMENT);
$getopt = new Getopt([$encOption]);
$getopt->parse();
$encoding = $getopt["encoding"] ? $getopt["encoding"] : "UTF-8";
$operands = $getopt->getOperands();

if(count($operands) == 0) {
    usage("Missing parameters");
    exit;
}

foreach($operands as $sourcefile) {
    $destinationfile = substr($sourcefile, 0,-3) . "sql";
    $destination = fopen($destinationfile, 'w');
    $source = new Table($sourcefile, null, $encoding);

    echo "Processing " . $source->getRecordCount() . " records from file $sourcefile to $destinationfile using $encoding encoding\n";

    $tableName = basename(strtolower($source->getName()), ".dbf");
    $createString = "CREATE TABLE " . escName($tableName) . " (\n";
    foreach($source->getColumns() as $column) {
        if(($column->getType() == Record::DBFFIELD_TYPE_MEMO) || ($column->getName() == "_nullflags")) {
            continue;
        }
        $createString .= "\t" . escName($column->getName()) . " ";
        $createString .= mapTypeToSql($column->getType(), $column->getLength(), $column->getDecimalCount());
        $createString .= ",\n";
    } 
    $createString = substr($createString, 0, -2) . "\n) CHARACTER SET utf8 COLLATE utf8_unicode_ci;\n";
    fwrite($destination, $createString);

    while ($record = $source->nextRecord()) {
        if($record->isDeleted()) { 
            continue; 
        }
        $insertLine = "INSERT INTO " . escName($tableName) . " VALUES (";
        foreach($source->getColumns() as $column) {
            if(($column->getType() == Record::DBFFIELD_TYPE_MEMO) || ($column->getName() == "_nullflags")) {
                continue;
            }
            $cell = $record->getObject($column);
            if(($column->getType() == Record::DBFFIELD_TYPE_DATETIME) && $cell) {
                $cell = date('Y-m-d H:i:s', $cell-3600);
            }
            $insertLine .= "\"" . addslashes($cell) . "\",";
        }
        $insertLine = substr($insertLine, 0, -1) . ");\n";
        fwrite($destination, $insertLine);
    }
    fclose($destination);
    echo "Export done: " . $source->getDeleteCount() . " deleted records ommitted\n";
}

function mapTypeToSql($type_short, $length, $decimal) {
    switch ($type_short) {
        case Record::DBFFIELD_TYPE_MEMO: return "TEXT";                        // Memo type field
        case Record::DBFFIELD_TYPE_CHAR: return "VARCHAR($length)";            // Character field
        case Record::DBFFIELD_TYPE_DOUBLE: return "DOUBLE($length,$decimal)";  // Double
        case Record::DBFFIELD_TYPE_NUMERIC: return "INTEGER";                  // Numeric
        case Record::DBFFIELD_TYPE_FLOATING: return "FLOAT($length,$decimal)"; // Floating point
        case Record::DBFFIELD_TYPE_DATE: return "DATE";                        // Date
        case Record::DBFFIELD_TYPE_LOGICAL: return "TINYINT(1)";               // Logical - ? Y y N n T t F f (? when not initialized).
        case Record::DBFFIELD_TYPE_DATETIME: return "DATETIME";                // DateTime
        case Record::DBFFIELD_TYPE_INDEX: return "INTEGER";                    // Index
   }
}

function escName($name) {
    return "`" . $name . "`";
}
