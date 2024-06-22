## Git in PHP

An attempt to port the isomorphic-git client to PHP so that
it can be used without a terminal `git` client, e.g. in WordPress
or PHP.wasm.

This is a rudimentary first stab that is able to:

* ls-refs
* fetch a list of objects related to a specific commit

It doesn't yet know how to parse the refs or decode the tree
object or the commit object – implementing those features would
be the next step.

## Next steps

* Port the parsers of binary formats form isomorphic-git to PHP.
* Port other flows described in [Cloning a Git Repository From a Web Browser Using fetch()](https://adamadam.blog/2024/06/21/cloning-a-git-repository-from-a-web-browser-using-fetch/).
