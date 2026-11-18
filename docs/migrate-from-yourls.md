# moving from YOURLS

[Deutsche Fassung](migrate-from-yourls.de.md)

dalang can import your YOURLS links from a CSV file (comma-separated values). YOURLS does not have to run for this. You only need its database. This was my own situation: the YOURLS application did not work any more, but the data was complete.

## what dalang imports, and what not

YOURLS stores its links in one table: `yourls_url`. Your table can have a different prefix than `yourls_`. Look for `YOURLS_DB_PREFIX` in the `config.php` of YOURLS. dalang imports four columns from this table:

| YOURLS | dalang | Note |
|---|---|---|
| `keyword` | code | Only `a-z`, `0-9` and `-`, at most 40 characters. It must not start with `admin`. |
| `url` | target | Only `http://` and `https://`. |
| `title` | title | dalang cuts it after 1000 characters. |
| `timestamp` | created | dalang uses the value without changes. |
| `clicks`, `ip` | - | dalang does not import them. dalang does not count clicks and does not store IP addresses. |

The import does not import or repair rows that break these rules. The message tells you how many rows it rejected, and the line numbers. You can then correct these rows yourself.

YOURLS has a setting for upper case keywords (base 62). With this setting, keywords can contain upper case letters. dalang rejects these rows, because dalang codes are lower case only.

## 1. export a CSV file from YOURLS

The file needs the columns in this order: keyword, url, title, timestamp. A header line is allowed. The import ignores all columns after the fourth. [csv.md](csv.md) has the details.

**With phpMyAdmin or Adminer** (web tools for MySQL databases): run this query. Then export the result as CSV, with commas as separators and fields in double quotes.

```sql
SELECT keyword, url, COALESCE(title, '') AS title, timestamp
FROM yourls_url
ORDER BY timestamp;
```

**On the command line:** the `mysql` client writes tabs, not CSV. This query creates the CSV lines itself:

```sh
mysql -N -r -u USER -p DATABASE -e "
SELECT CONCAT_WS(',',
  keyword,
  CONCAT('\"', REPLACE(url, '\"', '\"\"'), '\"'),
  CONCAT('\"', REPLACE(COALESCE(title, ''), '\"', '\"\"'), '\"'),
  timestamp)
FROM yourls_url ORDER BY timestamp" > links.csv
```

Please note: the tests cover the import in dalang. But I have not run this command line against a real YOURLS database. Check the result: `wc -l links.csv` must show the number of your links.

The file contains all your links. Do not add it to a git repository.

## 2. import the file

1. Log in at `/admin/`.
2. Open the view "Tools". Under "Backup and import", choose the file and click "Import CSV".
3. Read the message, for example: `Import done: 250 new, 0 already there, 0 rejected.`

The import does not change codes that already exist in dalang. So you can run the import twice without damage. The second run reports all links as "already there".

The example nginx configuration accepts uploads up to 2 MB. Is your file bigger? Then increase `client_max_body_size` in nginx and `upload_max_filesize` in PHP. Or split the file into smaller files.

## 3. check the result

Test some of your old short links:

```sh
curl -sI https://example.org/your-old-code
```

The answer must show `301`, and your target in the `Location` header. Then click "Save all links as CSV" once. Store the file in a safe place. Now you have a backup in the format of dalang.

## the old domain

Do you keep the same short domain? Then your old links work again as soon as the domain points to dalang. The import also imports links with targets that no longer exist. dalang does not check if a target still exists.
