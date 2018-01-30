# php-demork
demork is a PHP script that parses a Mork database file and prints the data. It's not bulletproof, but works well for my limited purposes.

## Background
The Mork file format is used in some Mozilla applications, Thunderbird being the most notable. Although the format has been announced to be dropped in favour of SQLite years ago, it is still around.
Information on the Mork file format can be found [here](https://developer.mozilla.org/en-US/docs/Mozilla/Tech/Mork) and [here](https://developer.mozilla.org/en-US/docs/Mozilla/Tech/Mork/Structure). It is what I used to put this code together.

## Design
The all-in-one file consists of a class **Mork** for parsing a Mork (.mab) file, and a wrapper script for instantiating the class and settings options for it from the command line. You can, of course, isolate the class and do other things with it.

The script produces either a summary, CSV output, or JSON output. If you were looking for direct conversion to VCard, look elsewhere or roll your own CSV-to-VCard or JSON-to-VCard code.

demork requires PHP's CLI module; it was tested on PHP 7 only, but is assumed to work on older versions as well.
Note that it was not written with performance in mind. But then again, storing really large tables in Mork format is a bad idea anyway.

## Installation
Not required. Should run as-is. Do make the file executable (`sudo chmod a+x`).

## Usage
```
Syntax:  demork.php  [-h | [ [-t <id>] [-n] [-C | -J] [-comma | -colon | -tab] [-p] [-P] [-s] [-v | -vv] ] <mork file>

General options:
-h        Displays help. Should not be combined with other options.
-?        Identical to -h.
-s        Sets strict mode. Makes the script break on unexpected EOF, non-matching group delimiters and the like.
-v        Prints verbose output. Useful for debugging.
-vv       Prints very verbose output. May be a bit detailed.
          If both -v and -vv are found, the most verbose option prevails.
Data options:
-t <id>]  Exports data from table with id <id>. The default is to export all tables.
-n        No filter. By default, the script only exports records with the same scope as the table they are in.
          This setting ignores that, and exports all records in a table, regardless of their scope.
-P        Prints scope names with the table and row ids.
          In CSV two extra columns are inserted for the scope names of tables and records.
          In JSON the scope name is printed with the id, separated by a colon (:).
Format options:
          The default format (no C or J switch) is to print a summary of the data.
          This is a useful starting point for exploring the data and deciding which table to export.
          If both C and J are found, the lattermost option prevails.
-C        Prints CSV format. The default delimiter is a semicolon (;). All strings are double-quoted.
-comma    Sets the CSV delimiter to a comma (,). Used together with the -C switch, otherwise ignored.
-colon    Sets the CSV delimiter to a colon (:). Used together with the -C switch, otherwise ignored.
-tab      Sets the CSV delimiter to a tab (ASCII value \x09). Used together with the -C switch, otherwise ignored.
          If more than one delimiter option is found, the lattermost prevails.
-J        Prints JSON format.
-p        Makes the JSON output more readable. Used together with the -J switch, otherwise ignored.
```
