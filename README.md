# logger-php
Repository for exporting our PHP Logger

`LOG_FULLPATH` and `LOG_DIR` may be set as environment variables, otherwise the log path defaults to
`/var/log/paperg/placelocal`

# Usage:
```
$contextObject = ["hello" => "hi!"];
ApiLog::info("Some text", $contextObject);
LogTags::add("CampaignId", "Banana");
ApiLog::info("Some text", $contextObject);
```
Outputs:
```
[api:info] Some text  {"hello":"hi"}
[api:info] [CampaignId: Banana] Some text  {"hello":"hi"}
```

