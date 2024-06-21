## Git sparse checkout in the browser

This is a simple example of how to use Git sparse checkout in the browser. It uses the Git HTTP protocol and a CORS proxy to fetch specific paths from a Git repository.

To try locally, install Bun and run the following commands:

```sh
bun install
bun run dev
```

And go to http://localhost:8000/ to see this page:

![Demo screenshot](https://raw.githubusercontent.com/adamziel/git-sparse-checkout-in-js/trunk/screen.png)

### How it works

The browser uses the Fetch API to communicate with the Git repository using the HTTP protocol. See the `main.js` file for the implementation details. The `sparseCheckout` function looks like this:

```js
export async function sparseCheckout(
    repoUrl,
    ref,
    paths,
) {
    const refs = await lsRefs(repoUrl, ref);
    const commitHash = refs[ref];
    const treesIdx = await fetchWithoutBlobs(repoUrl, commitHash, paths);
    const objects = await resolveObjects(treesIdx, commitHash, paths);

    const blobsIdx = await fetchObjects(repoUrl, paths.map(path => objects[path].oid));
    const fetchedPaths = {};
    await Promise.all(paths.map(async path => {
        fetchedPaths[path] = await extractGitObjectFromIdx(blobsIdx, objects[path].oid)
    }));
    return fetchedPaths;
```

This project uses `isomorphic-git` to parse Git responses that contain plenty of subformats (PACK, trees, deltas, etc.).

### CORS proxy

Git repositories typically do not expose the Access-Control-* headers required for CORS. To work around this, the project uses a simple, Node.js-based CORS proxy. See bin/cors-proxy.js for more details.

