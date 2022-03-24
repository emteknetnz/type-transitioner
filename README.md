# type-transitioner

Assist with migration from dynamic typing to static typing

## Data collection test
- github.com/emteknetnz/recipe-kitchen-sink - 4 build has this installed it - gha artifacts ett.txt files has raw data.

## Note as to why this work was not suitable for PHP8.1

`_c('string', $arg, 1)` isn't appropriate for php8.1 because all the _a() data collection will tell us what arg types were passed in.  However if we get a bunch of `string` AND `null` - then is the param type `string`, or is it `string|null`.  There's no easy way to tell if all the `null` args should be allowed or not.

Therefore we cannot comfortably put in `_c('string', $arg, 1)` because maybe we should allow `null` values for `$arg`?  There's no way we can tell if it should be `string` or `?string`

Seems like we need to wait until CMS5, and then just make it `string` and manually update every `null` method call to a blank string
