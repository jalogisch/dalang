# moving from YOURLS

[Deutsche Fassung](migrate-from-yourls.de.md)

dalang can read your YOURLS links from a CSV file. Your YOURLS does not have to run for this, you only need its database. That was my own situation: the application was broken, the data was fine.

## what comes along, and what does not

YOURLS keeps its links in one table, `yourls_url` (the prefix `yourls_` can be different, look at `YOURLS_DB_PREFIX` in your `config.php`). dalang takes four of its columns:

| YOURLS | dalang | Note |
|---|---|---|
| `keyword` | code | Only `a-z`, `0-9` and `-`, at most 40 characters, not starting with `admin`. |
| `url` | target | Only `http://` and `https://`. |
| `title` | title | Cut after 1000 characters. |
| `timestamp` | created | Taken as it is. |
| `clicks`, `ip` | - | Not imported. dalang does not count and does not store addresses. |

Rows that do not fit are not imported and not repaired. The import tells you how many it rejected and in which lines, so you can fix them by hand. If your YOURLS uses upper case keywords (the base 62 setting), those rows will be rejected: dalang codes are lower case only.

## 1. export a CSV

The file needs the columns in this order: keyword, url, title, timestamp. A header line is fine, more columns after the fourth are ignored. The details are in [csv.md](csv.md).

**With phpMyAdmin or Adminer:** run this query, then export the result as CSV (comma separated, fields enclosed in double quotes).

```sql
SELECT keyword, url, COALESCE(title, '') AS title, timestamp
FROM yourls_url
ORDER BY timestamp;
```

**On the command line:** the `mysql` client writes tabs, not CSV. This query builds the CSV lines itself:

```sh
mysql -N -r -u USER -p DATABASE -e "
SELECT CONCAT_WS(',',
  keyword,
  CONCAT('\"', REPLACE(url, '\"', '\"\"'), '\"'),
  CONCAT('\"', REPLACE(COALESCE(title, ''), '\"', '\"\"'), '\"'),
  timestamp)
FROM yourls_url ORDER BY timestamp" > links.csv
```

Honest note: the import in dalang is tested. This command line I have not run against a live YOURLS database. Check the result: `wc -l links.csv` should give the number of your links.

The file contains all your links. Do not put it into a git repository.

## 2. import

1. Log in at `/admin/`.
2. Open "Tools". Under "Backup and import", choose the file and click "Import CSV".
3. Read the message: `Import done: 250 new, 0 already there, 0 rejected.`

Codes that already exist in dalang stay as they are. So you can run the import twice without harm, the second run reports everything as "already there".

The example nginx config accepts uploads up to 2 MB. If your file is bigger, raise `client_max_body_size` (and `upload_max_filesize` in PHP) or split the file.

## 3. check

Try a few of your old short links:

```sh
curl -sI https://example.org/your-old-code
```

You want to see `301` and your target in `Location`. Then click "Save all links as CSV" once and put the file somewhere safe. Now you have a backup in dalang's own format.

## the old domain

If the short domain stays the same, your old links work again as soon as it points to dalang. Dead targets are imported like all others. dalang does not check if a target is still alive.
