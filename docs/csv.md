# the CSV format

[Deutsche Fassung](csv.de.md)

"Save all links as CSV" in the admin writes this format, "Import CSV" reads it. It is the backup of dalang, and the way in for links from somewhere else.

```csv
code,url,title,created
abc,https://example.org/a/long/address,,2026-01-02 03:04:05
my-talk,https://example.org/talk?year=2026,"A title with, comma and ""quotes""","2026-02-03 04:05:06"
```

## the columns

Four columns, always in this order:

| # | Name | Content |
|---|---|---|
| 1 | `code` | The short code: `a-z`, `0-9` and `-`, 1 to 40 characters, not starting with `admin`. |
| 2 | `url` | The target: `http://` or `https://` with a host, at most 2000 characters. |
| 3 | `title` | Free text, may be empty. |
| 4 | `created` | Date and time in UTC, written as `YYYY-MM-DD HH:MM:SS`. |

- **Header line:** the first line of an export is always `code,url,title,created`, in English, whatever language the admin speaks.
- **Encoding:** UTF-8, without a byte order mark.
- **Separator:** comma.
- **Quotes:** a field stands in double quotes when it needs them: when it contains a comma, a double quote, a space or a line break. A double quote inside a field is written twice (`""`). That is why the date is quoted in an export, it contains a space. There is no backslash escape.
- **Line end:** a line feed (`\n`).
- **Order:** oldest link first.

## what the import does

The import reads by position, not by name. Column 1 is the code, column 2 the target, and so on. The names in the header line do not matter, so a list from YOURLS with `keyword,url,title,timestamp` works as well (see [migrate-from-yourls.md](migrate-from-yourls.md)).

- **Header line:** there is no check for names. The rule is simpler: if the second field of the first line is not a valid target, the first line is skipped. So a file with a header and a file without one both work. The other side of this rule: a first line that is meant as a link but has a broken target is skipped silently too, it is not counted as rejected.
- **Empty lines** are skipped.
- **More columns** after the fourth are ignored. Fewer are fine too: a missing title is empty, a missing date is "now".
- **Code and target** are checked like in the form. A line that does not pass is rejected, not repaired. The message names the numbers of the rejected lines (the first 20), counted from the top of the file, header included.
- **Title:** line breaks and runs of spaces become one space, and it is cut after 1000 characters. A title that is not valid UTF-8 becomes empty.
- **Date:** if it does not look like `YYYY-MM-DD HH:MM:SS`, the link gets the time of the import.
- **Codes that already exist stay as they are.** The import never overwrites. They are counted as "already there", so you can import the same file twice without harm.
- The quotes are optional when reading: `"2026-02-03 04:05:06"` and `2026-02-03 04:05:06` are the same.

A file saved by dalang and imported into an empty dalang gives exactly the same file again. The test checks this.

The example nginx config accepts uploads up to 2 MB.

## a warning

The CSV is a backup, not a spreadsheet. Titles come from the pages you shorten, so a title can start with `=`, and a spreadsheet program may read that as a formula. And if such a program saves the file again, it may change the separator, the dates or the encoding. Use a text editor.
