# type-transitioner

Assist with migration from dynamic typing to static typing

## Note

`$lineageJ = $this->getClassLineage($argTypeI);` should be
`$lineageJ = $this->getClassLineage($argTypeJ);`

However running appeared to get stuck in a recursive loop

However at this stage the team had alread agreed that we are not proceeding this with approach

## Scanning multiple modules at once

Have tested this works correctly:

```
for vendorPath in vendor/*; do
  if [[ $vendorPath =~ vendor/(silverstripe|symbiote|dnadesign) ]]; then
    for modulePath in $vendorPath/*; do
      if [ -f TESTS_RUNNING.json ]; then
        rm TESTS_RUNNING.json
      fi
      pu $modulePath
      if [ -f $modulePath/behat.yml ]; then
        [[ $modulePath =~ /([a-zA-Z0-0\-]+)$ ]]
        suite="${BASH_REMATCH[1]}"
        bha $suite
      fi
    done
  fi
done

```

It will essentially skip recipe-cms and other recipes that contain testsuites instead of tests of their own, though this desirable as they would only duplicate tests of regular modules

If behat has an 'unexpected alert' issue, it will not run subsequent behat tests within the modoule, however bash will still continue to the next module to run phpunit tests.


## Note as to why this work was not suitable for PHP8.1

`_c('string', $arg, 1)` isn't appropriate for php8.1 because all the _a() data collection will tell us what arg types were passed in.  However if we get a bunch of `string` AND `null` - then is the param type `string`, or is it `string|null`.  There's no easy way to tell if all the `null` args should be allowed or not.

Therefore we cannot comfortably put in `_c('string', $arg, 1)` because maybe we should allow `null` values for `$arg`?  There's no way we can tell if it should be `string` or `?string`

Seems like we need to wait until CMS5, and then just make it `string` and manually update every `null` method call to a blank string
