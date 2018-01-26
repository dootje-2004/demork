#!/usr/bin/php
<?php
/**
 *	Parses a Mork file and prints the data to stdout.
 */

// Check if script was correctly called.
if($argc < 2) {
	echo "\nUsage: {$argv[0]} [-h] [-t <id>] [-n] [-C | -J] [-comma | -colon | -tab] [-p] [-P] [-s] [-v | -vv] <mork file>\n\n";
	exit(1);
}
// Drop script name from command-line arguments.
$scriptName = basename(array_shift($argv));

// Last command-line argument is the input filename.
$fn = array_pop($argv);

// Print help if -h is found instead of a filename.
// Use a heredoc so we can include the script name as a variable (in case we rename this file).
if($fn === '-h' || $fn === '-?') { echo <<<HELPTEXT
\n{$scriptName} is a PHP script that parses a Mork database file and prints the data.
\nInvocation: {$scriptName} <options> <mork file>
\nGeneral options:
-h        Displays help. Should not be combined with other options.
-?        Identical to -h.
-s        Sets strict mode. Makes the script break on unexpected EOF, non-matching group delimiters and the like.
-v        Prints verbose output. Useful for debugging.
-vv       Prints very verbose output. May be a bit detailed.
          If both -v and -vv are found, the most verbose option prevails.
\nData options:
-t <id>]  Exports data from table with id <id>. The default is to export all tables.
-n        No filter. By default, the script only exports records with the same scope as the table they are in.
          This setting ignores that, and exports all records in a table, regardless of their scope.
-P        Prints scope names with the table and row ids.
          In CSV two extra columns are inserted for the scope names of tables and records.
          In JSON the scope name is printed with the id, separated by a colon (:).
\nFormat options:
          The default format (no C, J or V switch) is to print a summary of the data.
          This is a useful starting point for exploring the data and deciding which table to export.
          If a combination of C, J and V is found, the lattermost option prevails.
-C        Prints CSV format. The default delimiter is a semicolon (;). All strings are double-quoted.
-comma    Sets the CSV delimiter to a comma (,). Used together with the -C switch, otherwise ignored.
-colon    Sets the CSV delimiter to a colon (:). Used together with the -C switch, otherwise ignored.
-tab      Sets the CSV delimiter to a tab (ASCII value \\x09). Used together with the -C switch, otherwise ignored.
          If more than one delimiter option is found, the lattermost prevails.
-J        Prints JSON format.
-p        Makes the JSON output more readable. Used together with the -J switch, otherwise ignored.
\nMore information on the Mork file format can be found at
          https://developer.mozilla.org/en-US/docs/Mozilla/Tech/Mork
          https://developer.mozilla.org/en-US/docs/Mozilla/Tech/Mork/Structure
\n
HELPTEXT;
	exit(0);
}

// Instantiate Mork class.
$mork = new Mork;

// Evaluate command-line options and set $mork's properties accordingly.
$i = 0;
while($i < count($argv)) {
	switch($argv[$i]) {
		case '-s': // Enforce strict syntax.
			$mork->setStrict(true);
			break;
		case '-v': // Verbosity.
			if($mork->getVerbosity() < 1) $mork->setVerbosity(1);
			break;
		case '-vv': // Increased verbosity.
			$mork->setVerbosity(2);
			break;
		case '-t': // Table id to export.
			$mork->setTableId($argv[++$i]);
			break;
		case '-n':
			$mork->setScopeFilter(false);
			break;
		case '-P': // Include scope names in output.
			$mork->setIncludeScope(true);
			break;
		case '-C': // Produce CSV output.
			$mork->setFormat(Mork::OUTPUTCSV);
			break;
		case '-comma': // Set CSV delimiter to comma.
			$mork->setCSVDelimiter(",");
			break;
		case '-colon': // Set CSV delimiter to colon.
			$mork->setCSVDelimiter(":");
			break;
		case '-tab': // Set CSV delimiter to tab.
			$mork->setCSVDelimiter("\t");
			break;
		case '-J': // Produce JSON output.
			$mork->setFormat(Mork::OUTPUTJSON);
			break;
		case '-p': // Pretty print JSON output.
			$mork->setPretty(true);
			break;
		default:
			echo "WARNING: Ignoring option {$argv[$i]}\n";
	}
	$i++;
}

// Get to work and print output.
$mork->parseFile($fn);
exit(0);

/* ======================================== CLASS DEFINITION ======================================== */

class Mork {
/**
 * The class Mork represents a data structure from a Mork file.
 * It can output data in CSV and JSON format in UTF-8 encoding.
 *
 * The Mork format is still used in some Mozilla products like Thunderbird and Firefox, although it should have been replaced
 * by SQLite long ago. Currently the only version of this format that can be encountered in the wild is 1.4.
 *
 * Basically a Mork file consists of several dictionaries, a number of tables and a set of groups (usually in that order).
 *
 * A dictionary (or briefly dict) is a list of key-value pairs defining all data strings longer than 3 characters.
 * In practice only two dicts are used, one for column names and one for basic data (aka atoms).
 * A dict may contain any metadata, but only 'a=c' is meaningful in this Mork version.
 *
 * A table contains a set of rows. Each row contains a set of cells with key-value pairs defining the data itself.
 * There is no fixed row definition. In that respect Mork is a true key-value database.
 * Any metadata in a table is ignored by this class. It may make sense to the application that uses the Mork file, but not to us.
 * Both tables and rows are scoped (i.e. they live in a certain namespace).
 * Row scope may be different from table scope, but the default is to inherit it.
 *
 * A group is a set of changes on the data, and must be treated as an atomic transaction.
 * Essentially, this makes a Mork file a combination of a database table and a database journal.
 *
 * Mork has no notion of indexing. Therefore, the format is unsuited for large tables.
 * An application needs to be able to ingest a Mork file in its entirety (also because all groups must be processed).
 * Any data consolidation or vacuuming is left to the application. There are no separate tools for this.
 * If an application omits this, a Mork file can grow quite large without actually containing more data.
 *
 * This Mork class stores the data in a straightforward table -> row -> cell format in its $data property.
 * The entry point for parsing an input file is the parseFile() method.
 * What data is parsed and how output is printed can be influenced by a number of setter functions:
 *
 * setCSVDelimiter()     Set the CSV delimiter to something other than a semicolon.
 * setEscape()           Set the escape character to something other than a backslash.
 *                       Apparently Mork applications have this freedom, but it is likely never used.
 * setFormat()           Sets one of the output formats (CSV or JSON). The default is to print a summary.
 * setIncludeScope()     True if the scope names must be printed with each table and row id.
 * setPretty()           If true, uses JSON_PRETTY_PRINT to improve readability of JSON output.
 * setScopeFilter()      If false, includes rows with scope different from their table's. Default is true.
 * setStrict()           Makes parsing break on irregularities like unexpected EOF and non-matching group delimiters.
 * setTableId()          Limits the output to rows from one table. Default is to export all tables.
 * setVerbosity()        Can be 0 (silent), 1 (some progress info) or 2 (very detailed).
 */

	const VERSION = '1.4';											// The Mork version that this class understands.
	const ESCAPE = '\\';												// Default escape character.
	const REGEXHDR = '%^//\s*<!-- <mdb:mork:z v="(.*)"/> -->%';	// Regular expression for the header.
	const OUTPUTSTATS = 0;											// Produce output with summary information.
	const OUTPUTCSV = 1;												// Produce output in CSV format.
	const OUTPUTJSON = 2;												// Produce output in JSON format.
	
	private $csvDelimiter = ';';								// Delimiter for CSV output.
	private $data = [];													// Data tree. Levels: table, row, property.
	private $dict = [];													// Universal dictionary. Keys must include scope.
	private $esc = '\\';												// Default escape character;
	private $fh;																// File handle.
	private $filterScope = true;								// Row scope must equal table scope.
	private $format = 0;												// Output format. Default is OUTPUTSTATS.
	private $pretty = false;										// Pretty JSON.
	private $printScope = false;								// Include scope names in output.
	private $scopeDict = 'a';										// Default dictionary scope is 'atom'.
	private $scopeRow = '';											// Default row scope is undefined.
	private $scopeTable = '';										// Default table scope is undefined.
	private $strict = false;										// Enforce strict syntax.
	private $strings2ignore = ["\\\x0D", "\\\x0A", "\x0A", "\x0D"];
																							// Line breaks and line continuations that should be ignored.
	private $tableId = '*';											// Table to export.
	private $verbosity = 0;											// Determines amount of output details.
	
	/**
	*	Outputs data in CSV format.
	*/
	private function csv() {
		// Collect field ids and sort them. We can't use the dictionary for this, as that also contains scope names.
		$colNames = [];
		foreach($this->data as $tkey => $table) {
			list($tid, $tsc) = explode(':', $tkey);
			if(($this->tableId !== '*') && ($tid != $this->tableId)) continue;
			foreach($table as $rkey => $row) {
				list($rid, $rsc) = explode(':', $rkey);
				if(($this->filterScope) && ($rsc !== $tsc)) continue;
				foreach($row as $pkey => $pval) $colNames[$pkey] = 1;
			}
		}
		// Lookup field names and sort them alphabetically.
		foreach($colNames as $ckey => $cval) $colNames[$ckey] = $this->lookup($ckey);
		asort($colNames, SORT_STRING | SORT_FLAG_CASE);
		
		// Output the header row.
		$csvRow = ['Table id'];
		if($this->printScope) $csvRow[] = 'Table scope';
		$csvRow[] = 'Row id';
		if($this->printScope) $csvRow[] = 'Row scope';
		foreach($colNames as $ckey => $cval) $csvRow[] = $cval;
		$this->say($this->csvify($csvRow));

		// Output the data.
		foreach($this->data as $tkey => $table) {
			list($tid, $tsc) = explode(':', $tkey);
			if(($this->tableId !== '*') && ($tid != $this->tableId)) continue;
			foreach($table as $rkey => $row) {
				list($rid, $rsc) = explode(':', $rkey);
				if(($this->filterScope) && ($rsc !== $tsc)) continue;
				$csvRow = [hexdec($tid)];
				if($this->printScope) $csvRow[] = $this->lookup($tsc . ':c');
				$csvRow[] = hexdec($rid);
				if($this->printScope) $csvRow[] = $this->lookup($rsc . ':c');
				foreach($colNames as $ckey => $cval) {
					$csvRow[] = $this->lookup($this->data[$tkey][$rkey][$ckey]);
				}
				$this->say($this->csvify($csvRow));
			}
		}
	}
	
	/**
	* Helper function. Creates escaped and delimited lines for use in CSV output.
	*
	* @param array $ar					Array with strings to escape and delimit.
	* @return string						Escaped and delimited CSV line.
	*/
	private function csvify($ar) {
		foreach($ar as $k => $v) $ar[$k] = '"'. addslashes($v) . '"';
		return str_replace(["\x0A", "\x0D"], ' ', implode($this->csvDelimiter, $ar));
	}
	
	/**
	* Returns the verbosity.
	*
	* @return integer        The current verbosity level.
	*/
	public function getVerbosity() {
		return $this->verbosity;
	}
	
	/**
	*	Prints a JSON string.
	*	
	* @return JSON									// Data in JSON string format.
	*/
	private function json() {
		$ar = [];

		foreach($this->data as $tkey => $table) {
			list($tid, $tsc) = explode(':', $tkey);
			if(($this->tableId !== '*') && ($tid != $this->tableId)) continue;
			$tid = hexdec($tid);
			if($this->printScope) $tid .= ':' . $this->lookup($tsc . ':c');
			$ar[$tid] = [];
			foreach($table as $rkey => $row) {
				list($rid, $rsc) = explode(':', $rkey);
				if(($this->filterScope) && ($rsc !== $tsc)) continue;
				$rid = hexdec($rid);
				if($this->printScope) $tid .= ':' . $this->lookup($rsc . ':c');
				$ar[$tid][$rid] = [];
				foreach($row as $pkey => $pval) {
					$ar[$tid][$rid][$this->lookup($pkey)] = $this->lookup($pval);
				}
			}
		}
		$ret = json_encode($ar, $this->pretty ? JSON_PRETTY_PRINT : null);
		echo $ret ?: json_last_error();
	}
	
	/**
	*	Look up a dictionary value.
	*	
	*	@param string $v				The value to look up. Must be a fully qualified oid or a literal.
	*	@return string					The (unescaped) value of $v, or '??' if it is not found.
	*/
	private function lookup($v) {
		$this->say2("Looking up", $v);
		if(substr($v, 0, 1) === '^') {
			$this->say2($v, "is a reference");
			$ret = $this->dict[substr($v, 1)] ?: "??";
			$this->say2("Lookup value is", $ret);
			return $this->unescape($ret);
		} else {
			$this->say2($v, "is a literal; no lookup");
			return $this->unescape($v);
		}
	}
	
	/**
	*	Explicitly sets the id's scope (in-place) and returns a creation status.
	*	
	*	@param string $id						// Identifier.
	*	@return string 							// The id's status: true if new, false if existing.
	*/
	private function normalizeId(&$id, $defaultScope) {
		$noCreate = substr($id, 0, 1);
		if($noCreate === '-') $id = substr($id, 1); else $noCreate = false;
		if(strpos($id, ':') === false) $id .= ':' . $defaultScope;
		return !$noCreate;
	}
	
	/**
	* Parses an alias in a dictionary.
	*	The result is added to this class' $data property.
	*/
	private function parseAlias() {
		$this->readUntil($id, '=');
		$this->readUntil($val, ')');
		if(strpos($id, ':') === false) $id .= ':' . $this->scopeDict;
		$this->dict[$id] = $val;
		$this->say2("Alias ID", $id, "set to", $val);
		$this->say2("Closing ALIAS");
	}
	
	/**
	*	Parses the contents of a file.
	*	The result is stored in this class' $data property.
	*	
	*	@param $fn			File name.
	*/
	public function parseFile($fn) {
		$this->fh = @fopen($fn, "r");
		if(!$this->fh) throw new Exception("Unable to open file {$fn}.");
		
		// Check header.
		$header = $this->parseComment();
		if(preg_match(self::REGEXHDR, $header, $matches)) {
			if($matches[1] !== self::VERSION) throw new Exception("Wrong Mork version: {$matches[1]}.");
			$this->say1("Detected Mork version", $matches[1]);
		} else throw new Exception("Incorrect file header: {$header}");
		
		while (($token = $this->readUntil($dummy, '//', '@$${', '<', '{')) !== false) {
			switch($token) {
				case '//': // Comment
					$this->say2("Found COMMENT");
					$comment = $this->parseComment();
					$this->say1("Comment: {$comment}");
					break;
				case '@$${': // Group
					$this->say2("Found GROUP");
					$this->parseGroup();
					break;
				case '<': // Dict
					$this->say2("Found DICT");
					$this->parseDict();
					break;
				case '{': // Table
					$this->say2("Found TABLE");
					$this->parseTable();
					break;
				default: // EOF
					$this->say2("Found EOF");
			}
		}
		fclose($this->fh);
		
		// Produce output.
		switch($this->format) {
			case self::OUTPUTCSV:
				$this->csv();
				break;
			case self::OUTPUTJSON:
				$this->json();
				break;
			default:
				$this->stats();
				break;
		}
	}

	/**
	*	Parses a cell, starting from the current position in the input file.
	*	
	*	@return array			Array with elements $col, $slot.
	*/
	private function parseCell() {
		$sep = $this->readUntil($col, '^', '=');
		if($col === "" && $sep === '^') {
			$sep = $this->readUntil($col, '^', '=');
			$col = '^' . $col;
		}
		$this->readUntil($slot, ')');
		if($sep === '^') $slot = '^' . $slot;
		return array($col, $slot);
	}

	/**
	*	Reads a comment line.
	*	
	*	@return string		Comment.
	*/
	private function parseComment() {
		$this->readUntil($txt, "\x0A", "\x0D");
		return $txt;
	}

	/**
	* Parses a dictionary.
	*	The result is added to this class' $data property.
	*/
	private function parseDict() {
		while($d = $this->readUntil($dummy, '<', '(', '//', '>')) {
			switch($d) {
				case '<': // Metadict
					$this->say2("Found METADICT");
					$this->parseMetadict();
					break;
				case '>': // End of dict
					$this->say2("Closing DICT");
					$this->scopeDict = 'a';
					return;
				case '(': // Alias
					$this->say2("Found ALIAS");
					$this->parseAlias();
					/*
					list($id, $val) = $this->parseCell();
					$this->say2("Alias ID is", $id, ", value is", $val);
					if(strpos($id, ':') === false) $id .= ':' . $this->scopeDict;
					$this->dict[$id] = $val;
					$this->say2("ID", $id, "set to", $val);
					$this->say2("Closing ALIAS");
					*/
					break;
				case '//': // Comment
					$this->say2("Found COMMENT");
					$comment = $this->parseComment();
					$this->say1("Comment:", $comment);
					break;
				default: // EOF
					$this->say1("Found unexpected EOF");
					if($this->strict) throw new Exception("Strict syntax error: Missing DICT terminator, found EOF instead");
			}
		}
	}

	/**
	* Parses a group.
	*	The result is added to this class' $data property.
	*/
	private function parseGroup() {
		$this->readUntil($groupId, '{@');
		// Remember file position. If we find a COMMIT, we have to restart reading at this position.
		$filePos = ftell($this->fh);
		$this->say1("Found group id", $groupId);
		$commit = '@$$}' . $groupId . '}@';
		$abort = '@$$}~abort~' . $groupId . '}@';
		switch($d = $this->readUntil($dummy, $commit, $abort, '@$${')) {
			case $commit: // End of group - commit
				$this->say2("Found GROUP and COMMIT - retracing");
				break;
			case $abort: // End of group - abort
				$this->say2("Closing GROUP and ABORT");
				return;
			case '@$${': // New group
				$this->say2("Found nested GROUP");
				if($this->strict) throw new Exception("Strict syntax error: Nested groups are not allowed");
				$this->say2("Closing GROUP and ABORT implicitly");
				return;
			default: // EOF
				$this->say1("Found unexpected EOF");
				if($this->strict) throw new Exception("Strict syntax error: Missing GROUP terminator, found EOF instead");
				return;
		}
		// Reposition file pointer and take it from there.
		fseek($this->fh, $filePos);
		while(($d = $this->readUntil($dummy, $commit, '<', '{', '[', '(')) !== false) {
			switch($d) {
				case $commit: // End of group - commit
					$this->say2("Found GROUP and COMMIT (again)");
					break 2;
				case '<': // Dict
					$this->say2("Found DICT");
					$this->parseDict();
					break;
				case '{': // Table
					$this->say2("Found TABLE");
					$this->parseTable();
					break;
				case '[': // Row
					$this->say2("Found ROW");
					$this->parseRow();
					break;
				case '(': // Cell
					$this->say2("Found CELL");
					list($k, $v) = $this->parseCell();
					if($v) {
						$this->normalizeId($k, 'c');
						if(substr($v, 0, 1) === '^') $this->normalizeId($v, 'a');
						$this->say2("Setting", $k, "to", $v);
						if($tableId) $this->data[$tableId][$rowId][$k] = $v;
					}
					$this->say2("Closing CELL");
					break;
				default: // EOF
					$this->say1("Found unexpected EOF");
					if($this->strict) throw new Exception("Strict syntax error: Missing group terminator, found EOF instead");
			}
		}
		$this->say2("Closing GROUP");
	}
	
	/**
	* Parses an id, reading from the current file position.
	*
	* @return string       Identifier.
	*/
	private function parseId() {
		$d = $this->readUntil($id, ' ', '{', '[', '(', '=', '}', ']', ')');
		return $id;
	}
	
	/**
	* Parses a metadictionary.
	*	The result is added to this class' $data property.
	*/
	private function parseMetadict() {
		while($d = $this->readUntil($dummy, '(', '>')) {
			switch($d) {
				case '(': // Cell
					$this->say2("Found CELL");
					list($col, $slot) = $this->parseCell();
					$this->say2("Metadict setting", $col, "is", $slot);
					if($col === 'a') {
						$this->scopeDict = $slot;
						$this->say2("Dictionary scope set to", $this->scopeDict);
					} else {
						$this->say1("WARNING: Unhandled METADICT", $col, "=>", $slot);
					}
					$this->say2("Closing CELL");
					break;
				case '>': // End of metadict
					$this->say2("Closing METADICT");
					return;
			}
		}
		$this->say1("Found unexpected EOF");
		if($this->strict) throw new Exception("Strict syntax error: Missing METADICT terminator, found EOF instead");
	}

	/**
	* Parses a metarow.
	*	The result is added to this class' $data property.
	*/
	private function parseMetarow() {
		while($d = $this->readUntil($dummy, '(', ']')) {
			switch($d) {
				case '(': // Cell
					$this->say2("Found CELL");
					list($k, $v) = $this->parseCell();
					$this->say2("Setting", $k, "is", $v);
					$this->say2("Closing CELL");
					break;
				case ']': // End of metarow
					$this->say2("Closing METAROW");
					return;
			}
		}
		$this->say1("Found unexpected EOF");
		if($this->strict) throw new Exception("Strict syntax error: Missing METAROW terminator, found EOF instead");
	}

	/**
	* Parses a metatable.
	*	The result is added to this class' $data property.
	*/
	private function parseMetatable($tableId) {
		while($d = $this->readUntil($dummy, '(', '}')) {
			switch($d) {
				case '(': // Cell
					$this->say2("Found CELL");
					list($k, $v) = $this->parseCell();
					$this->say2("Setting", $k, "is", $v);
					// Since we don't have a clue what these settings mean, we ignore them.
					$this->say2("Closing CELL");
					break;
				case '}': // End of metatable
					$this->say2("Closing METATABLE");
					return;
				default: // EOF
					$this->say1("Found unexpected EOF");
					if($this->strict) throw new Exception("Strict syntax error: Missing METATABLE terminator, found EOF instead");
			}
		}
	}

	/**
	* Parses a row.
	*	The result is added to this class' $data property.
	*/
	private function parseRow($tableId = '') {
		$d = $this->readUntil($rowId, '[', '(', ']');
		if($create = $this->normalizeId($rowId, $this->scopeTable)) {
			$this->say2("Creating or updating row with id", $rowId);
		} else {
			$this->say2("Removing row with id", $rowId);
		}
		// Lookup table containing row with this id.
		if(!$tableId) {
			foreach($this->data as $kt => $table) {
				if(isset($this->data[$kt][$rowId])) $tableId = $kt;
			}
		}
		if(!$tableId) {
			$this->say("WARNING: Row id", $rowId, "does not belong to any table");
			if($this->strict) throw new Exception("Found orphaned row id {$rowId}");
		}
		if($create) {
			if(isset($this->data[$tableId][$rowId])) {
				$this->say2("Row", $rowId, "already exists");
			} else {
				$this->say2("Creating row with id", $rowId);
				$this->data[$tableId][$rowId] = [];
			}
		} else {
			if(isset($this->data[$tableId][$rowId])) {
				$this->say2("Deleting row", $rowId);
				unset($this->data[$tableId][$rowId]);
			} else {
				// Apparently removing a non-existing row is allowed in the Mork syntax (at least Thunderbird does it).
				/*
				$this->say2("WARNING: Attempting to delete non-existing row", $rowId);
				if($this->strict) throw new Exception("Attempting to delete non-existing row {$rowId}");
				*/
			}
		}
		while($d) {
			switch($d) {
				case '[': // Metarow
					$this->say2("Found METAROW");
					$this->parseMetarow($tableId, $rowId);
					break;
				case '(': // Cell
					$this->say2("Found CELL");
					list($k, $v) = $this->parseCell();
					$this->say2("Found setting", $k, ", value", $v);
					if($v) {
						// Ignore the <create> status of a cell.
						$this->normalizeId($k, 'c');
						if(substr($v, 0, 1) === '^') $this->normalizeId($v, 'a');
						$this->say2("Setting", $k, "to", $v);
						if($tableId) $this->data[$tableId][$rowId][$k] = $v;
					}
					$this->say2("Closing CELL");
					break;
				case ']': // End of row
					$this->say2("Closing ROW");
					return;
				default: // EOF
					$this->say1("Found unexpected EOF");
					if($this->strict) throw new Exception("Strict syntax error: Missing ROW terminator, found EOF instead");
			}
			$d = $this->readUntil($dummy, '[', '(', ']');
		}
		$this->say2("Closing ROW");
		$this->scopeRow = '';
	}

	/**
	* Parses a table.
	*	The result is added to this class' $data property.
	*/
	private function parseTable() {
		$this->scopeTable = 'c';
		// Look for table id.
		$d = $this->readUntil($dummy, 'HEX');
		$tableId = $d . $this->parseId();
		$this->normalizeId($tableId, $this->scopeTable);
		if(!isset($this->data[$tableId])) {
			$this->data[$tableId] = [];
			$this->say2("Creating new table with id", $tableId);
		}
		
		$this->say2("Table id is", $tableId);
		$this->scopeTable = explode(':', $tableId)[1];
		
		while(($d = $this->readUntil($dummy, '<', '{', '[', '}', 'HEX', '-')) !== false) {
			switch(strtoupper($d)) {
				case '<': // Dict
					$this->say2("Found DICT");
					$this->parseDict();
					break;
				case '{': // Metatable
					$this->say2("Found METATABLE");
					$this->parseMetatable($tableId);
					break;
				case '[': // Row
					$this->say2("Found ROW");
					$this->parseRow($tableId);
					break;
				case '}': // End of table
					$this->say2("Closing TABLE with id", $tableId);
					return;
				default: // HEX value (row id)
					$this->say2("Found ID");
					$id = $d . $this->parseId();
					if($this->normalizeId($id, $this->scopeTable)) {
						$oldId = explode(':', $id)[1];
						if(!isset($this->data[$tableId][$id])) $this->data[$tableId][$id] = [];
						unset($this->data[$tableId][$oldId]);
					}
			}
		}
		if($d === false) {
			$this->say1("Found unexpected EOF");
			if($this->strict) throw new Exception("Strict syntax error: Missing TABLE terminator, found EOF instead");
		}
		$this->say2("Closing TABLE");
		$this->scopeTable = '';
	}
	
	/**
	 *	Reads characters from a file until one of a list of specified strings is encountered.
	 *
	 *	@param $text			Returns the string read up until the matching token.
	 *	@param $tokens		Variable number of test strings; if one of them is encountered while
	 *										reading from the file, that token is returned.
	 *										Ensure that the tokens are ordered from most specific to least specific.
	 *										You may use 'HEX' as an abbreviation for hexadecimal characters (0-9A-Fa-f).
	 *	@return string		The matching token, or false if EOF was reached.
	 */
	function readUntil(&$text, $tokens) {
		$args = func_get_args();
		array_shift($args);
		// Check if abbreviation 'HEX' is among the arguments.
		if(in_array('HEX', $args)) {
			// Expand abbrevation to full array (upper and lower case).
			$args = array_merge($args, ['0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F','a','b','c','d','e','f']);
		}
		$s = "";
		$pattern = [];
		foreach($args as $arg) {
			$p = '';
			for($i = 0; $i < strlen($arg); $i++) {
				$p .= '\\x' . substr('0' . dechex(ord($arg[$i])), -2);
			}
			$pattern[] = $p;
		}
		$pattern = '%(?<!\\' . $this->esc . ')((' . implode('|', $pattern) . '))%';
		$this->say2("RegEx pattern is", $pattern);
		while (($c = fgetc($this->fh)) !== false) {
			$s .= $c;
			if(preg_match($pattern, $s, $matches)) {
				$text = trim(substr($s, 0, -strlen($matches[1])));
				$text = str_replace($this->strings2ignore, '', $text); // Remove line continuations.
				return $matches[1];
			}
		}
		return false;
	}

	/**
	* Prints regular output to stdout.
	*
	* @param mixed							List of strings.
	*/
	private function say() {
		echo implode(' ', func_get_args()) . "\n";
	}
	
	/**
	* Produces verbose output.
	*/
	private function say1() {
		if($this->verbosity > 0) echo implode(' ', func_get_args()) . "\n";
	}
	
	/**
	* Produces extra verbose output.
	*/
	private function say2() {
		if($this->verbosity > 1) echo implode(' ', func_get_args()) . "\n";
	}
	
	/**
	* Sets the CSV delimiter.
	*
	* @param string $delimiter				The delimiter to use in CSV output.
	*/
	public function setCSVDelimiter($delimiter) {
		$delimiter = trim($delimiter, "'");
		$this->csvDelimiter = $delimiter;
	}
	
	/**
	* Sets the escape character.
	*
	* @param string $e								The escape character to use when parsing input.
	*/
	public function setEscape($e) {
		$this->escape = $e;
		$this->strings2ignore = [$e . '\x0D', $e . '\x0A', '\x0A', '\x0D'];
	}
	
	/**
	* Sets the output format.
	*
	* @param integer $format					The output format (stats, CSV or JSON).
	*/
	public function setFormat($format) {
		switch($format) {
			case self::OUTPUTSTATS:
			case self::OUTPUTCSV:
			case self::OUTPUTJSON:
				$this->format = $format;
				break;
			default:
				$this->say1("Unknown output format {$format}");
				if($this->strict) throw new Exception("Unknown output format {$format}");
		}
	}
	
	/**
	* Makes output include scope names.
	*/
	public function setIncludeScope($include) {
		$this->printScope = (boolean) $include;
	}
	
	/**
	* Sets prettiness of JSON output.
	*/
	public function setPretty($pretty) {
		$this->pretty = (boolean) $pretty;
	}
	
	/**
	* Sets if row output must be filtered by the enveloping table's scope.
	*/
	public function setScopeFilter($f) {
		$this->filterByScope = (boolean) $f;
	}
	
	/**
	* Sets strict mode.
	*
	* @param boolean $strict					Sets or unsets strict mode.
	*/
	public function setStrict($st) {
		$this->strict = (boolean) $st;
	}
	
	/**
	* Sets the id of the table to export.
	*
	* @param string $tableId					Table id.
	*/
	public function setTableId($id) {
		$this->tableId = $id;
	}
	
	/**
	* Sets the verbosity.
	*
	* @param integer $verb						Sets the verbosity.
	*/
	public function setVerbosity($verb) {
		$this->verbosity = (int) $verb;
	}
	
	/**
	* Prints a summary.
	*/
	private function stats() {
		$scopes = [];
		foreach($this->data as $tkey => $table) {
			list($tid, $sc) = explode(':', $tkey);
			$sc = $this->lookup($sc . ':c');
			$cnt = count($table);
			$this->say("Table", $tid, "in scope", $sc, "has", $cnt, "row" . ($cnt === 1 ? "" : "s"));
			$scopes[$sc] = 1;
			$rowScopes = [];
			foreach($table as $rkey => $row) {
				list($rid, $sc) = explode(':', $rkey);
				$sc = $this->lookup($sc . ':c');
				$scopes[$sc] = 1;
				if(!isset($rowScopes[$sc])) $rowScopes[$sc] = 1; else $rowScopes[$sc]++;
				$colNames = [];
				foreach($row as $pkey => $pval) $colNames[$pkey] = 1;
			}
			foreach($rowScopes as $skey => $sval) $this->say("\t" . $sval, "row" . ($sval === 1 ? "" : "s"), "in scope", $skey);
			// Sort field names.
			foreach($colNames as $ckey => $cval) $colNames[$ckey] = $this->lookup($ckey);
			asort($colNames, SORT_STRING | SORT_FLAG_CASE);
			$cnt = count($colNames);
			$this->say("Table", $tid, "has", $cnt, "field" . ($cnt === 1 ? "" : "s"));
			foreach($colNames as $ckey => $cval) $this->say("\t" . $cval);
			$this->say();
		}
		$cnt = count($scopes);
		$this->say("Found", $cnt, "scope" . ($cnt === 1 ? "" : "s") . ":");
		foreach($scopes as $skey => $sval) $this->say("\t" . $skey);
	}
	
	/**
	* Converts escaped characters to their regular UTF-8 encoded equivalents.
	*
	* @param string $s					Escaped string.
	* @return string						Unescaped UTF-8 encoded string.
	*/
	private function unescape($s) {
		$esc = '\\' . $this->esc;
		// Unescape hexadecimal UTF-8 codes ($ syntax).
		// Use negative lookbehind to assure the escape character $ is not itself escaped.
		$s = preg_replace_callback('%(?<!' . $esc . ')\$([0-9A-F]{2})%i', function ($matches) {
			return chr(hexdec($matches[1]));
		}, $s);
		// Now we can unescape the rest.
		$s = preg_replace('%' . $esc . '(.)%', '$1', $s);
		return $s;
	}
}
