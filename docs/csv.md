# the CSV format

[Deutsche Fassung](csv.de.md)

CSV means "comma-separated values": a text file with one record per line. dalang uses one CSV format for two things:

- **Export:** "Save all links as CSV" writes this format. This file is the backup of dalang.
- **Import:** "Import CSV" reads this format. With it, you can also import links from another link shortener.

Both buttons are in the view "Tools" on the admin page.

```csv
code,url,title,created
abc,https://example.org/a/long/address,,2026-01-02 03:04:05
my-talk,https://example.org/talk?year=2026,"A title with, comma and ""quotes""","2026-02-03 04:05:06"
```

## the columns

A file has four columns, always in this order:

| # | Name | Content |
|---|---|---|
| 1 | `code` | The short code: `a-z`, `0-9` and `-`, 1 to 40 characters. It must not start with `admin`. |
| 2 | `url` | The target: `http://` or `https://` with a host name, at most 2000 characters. |
| 3 | `title` | Free text. It can be empty. |
| 4 | `created` | Date and time in UTC (Coordinated Universal Time), in the form `YYYY-MM-DD HH:MM:SS`. |

Rules for the export:

- **Header line:** The first line is always `code,url,title,created`. It is always in English, also when the admin page is in German.
- **Encoding:** UTF-8, without a byte order mark (BOM).
- **Separator:** a comma.
- **Quotes:** Some fields need double quotes. These are fields with a comma, a double quote, a space or a line break. A double quote inside a field is written twice (`""`). The date contains a space, so the export always puts it in quotes. dalang does not use backslash escapes.
- **Line end:** a line feed (`\n`).
- **Order:** the oldest link first.

## what the import does

The import reads the columns by position, not by name. Column 1 is the code, column 2 is the target, and so on. The names in the header line do not matter. So a list from YOURLS with the columns `keyword,url,title,timestamp` also works (see [migrate-from-yourls.md](migrate-from-yourls.md)).

- **Header line:** The import does not check column names. It uses a simpler rule: if the second field of the first line is not a valid target, the import skips the first line. So files with a header line and files without one both work. This rule has one side effect: a first line with a link and a broken target is also skipped. The import does not count it as rejected.
- **Empty lines:** The import skips them.
- **Number of columns:** The import ignores all columns after the fourth. Fewer columns are also allowed. A missing title stays empty. A missing date becomes the time of the import.
- **Code and target:** The import checks them with the same rules as the form. If a line fails the check, the import rejects it. It does not repair it. The message names the numbers of the rejected lines (the first 20 only). The count starts at the top of the file, header line included.
- **Title:** The import replaces line breaks and multiple spaces with one space. It cuts the title after 1000 characters. A title that is not valid UTF-8 becomes empty.
- **Date:** If the date does not have the form `YYYY-MM-DD HH:MM:SS`, the link gets the time of the import.
- **Existing codes stay unchanged.** The import never overwrites a link. It counts these lines as "already there". So you can import the same file twice without damage.
- **Quotes:** Quotes are optional for the import. `"2026-02-03 04:05:06"` and `2026-02-03 04:05:06` give the same result.

Export a file from dalang and import it into an empty dalang. A new export then gives exactly the same file. The test `deploy/test.sh` checks this.

The example nginx configuration accepts uploads up to 2 MB.

## a warning

The CSV file is a backup. It is not a spreadsheet. Titles come from the pages that you shorten. So a title can start with `=`, and a spreadsheet program can read this as a formula. A spreadsheet program can also change the separator, the dates or the encoding when it saves the file. Use a text editor to open the file.
