import { GitPktLine } from 'isomorphic-git/src/models/GitPktLine.js'
import { GitTree } from 'isomorphic-git/src/models/GitTree.js'
import { GitAnnotatedTag } from 'isomorphic-git/src/models/GitAnnotatedTag.js'
import { GitCommit } from 'isomorphic-git/src/models/GitCommit.js'
import { GitPackIndex } from 'isomorphic-git/src/models/GitPackIndex.js'
import { collect } from 'isomorphic-git/src/internal-apis.js'
import { GitRemoteManager } from 'isomorphic-git/src/managers/GitRemoteManager.js'
import { parseUploadPackResponse } from 'isomorphic-git/src/wire/parseUploadPackResponse.js'
import { parseRefsAdResponse } from 'isomorphic-git/src/wire/parseRefsAdResponse.js'
import { listpack } from 'isomorphic-git/src/utils/git-list-pack.js'
import http from 'isomorphic-git/src/http/web/index.js';
import { Buffer } from 'buffer'
window.Buffer = Buffer;

async function fetchRefs(repoUrl, refPrefix) {
    const packbuffer = Buffer.from(await collect([
        GitPktLine.encode(`command=ls-refs\n`),
        GitPktLine.encode(`agent=git/2.37.3\n`),
        GitPktLine.encode(`object-format=sha1\n`),
        GitPktLine.delim(),
        GitPktLine.encode(`peel\n`),
        GitPktLine.encode(`ref-prefix ${refPrefix}\n`),
        GitPktLine.flush(),
    ]));
    const res = await fetch(repoUrl+'/git-upload-pack', {
        method: 'POST',
        headers: {
            'Accept': 'application/x-git-upload-pack-advertisement',
            'content-type': 'application/x-git-upload-pack-request',
            'Content-Length': packbuffer.length,
            'Git-Protocol': 'version=2'
        },
        body: packbuffer,
    });
    const text = await res.text();
    console.log({text})
    const refs = {};
    for (const line of text.split('\n')) {
        if (line === '0000') break;
        const [ref, name] = line.slice(4).split(' ');
        refs[name] = ref;
    };
    return refs;
}


async function fetchObjectHashes(repoUrl, commitHash, paths) {
    const packbuffer = Buffer.from(await collect([
        GitPktLine.encode(`want ${commitHash} multi_ack_detailed no-done side-band-64k thin-pack ofs-delta agent=git/2.10.1.windows.1 filter \n`),
        GitPktLine.encode(`filter blob:none\n`),
        GitPktLine.encode(`shallow ${commitHash}\n`),
        GitPktLine.encode(`deepen 1\n`),
        GitPktLine.flush(),
        GitPktLine.encode(`done\n`),
        GitPktLine.encode(`done\n`),
    ]));

    const raw = await GitRemoteHTTP.connect({
        http,
        onProgress: null,
        // (args) {
        //     console.log({ args });
        // },
        service: 'git-upload-pack',
        url: repoUrl,
        auth: {},
        body: [packbuffer],
        headers: {},
    })

    const response = await parseUploadPackResponse(raw.body)
    const packfile = Buffer.from(await collect(response.packfile))
    const idx = await GitPackIndex.fromPack({
        pack: packfile
    });
    const originalRead = idx.read;
    idx.read = async function ({ oid, ...rest }) {
        const result = await originalRead.call(this, { oid, ...rest });
        result.oid = oid;
        return result;
    }
    console.log(idx);

    const commit = await idx.read({
        oid: commitHash
    });
    readObject(commit);

    let rootTree = await idx.read({ oid: commit.object.tree });
    readObject(rootTree);

    // Resolve refs to fetch
    const resolvedRefs = {};
    for (const path of paths) {
        let currentObject = rootTree;
        const segments = path.split('/');
        for (const segment of segments) {
            if (currentObject.type !== 'tree') {
                console.log({ segment, currentObject })
                throw new Error(`Path not found in the repo: ${path}`);
            }

            let found = false;
            for (const item of currentObject.object) {
                if (item.path === segment) {
                    currentObject = await idx.read({ oid: item.oid });
                    readObject(currentObject);
                    found = true;
                    break;
                }
            }
            if (!found) {
                throw new Error(`Path not found in the repo: ${path}`);
            }
        }
        resolvedRefs[path] = currentObject;
    }
    return resolvedRefs;
}

// Request oid for each resolvedRef
async function fetchTree(url, treeHash) {
    console.log("Tree", treeHash)
    const packbuffer2 = Buffer.from(await collect([
        GitPktLine.encode(`want ${treeHash} multi_ack_detailed no-done side-band-64k thin-pack ofs-delta agent=git/2.10.1.windows.1 \n`),
        // GitPktLine.encode(`shallow ${treeHash}\n`),
        // GitPktLine.encode(`deepen 1\n`),
        GitPktLine.flush(),
        // GitPktLine.encode(`done\n`),
        GitPktLine.encode(`done\n`),
    ]));
    console.log(packbuffer2.toString('utf8'));
    const raw2 = await GitRemoteHTTP.connect({
        http,
        onProgress: null,
        // (args) {
        //     console.log({ args });
        // },
        service: 'git-upload-pack',
        url,
        auth: {},
        body: [packbuffer2],
        headers: {},
    })

    const response2 = await parseUploadPackResponse(raw2.body)
    const packfile2 = Buffer.from(await collect(response2.packfile))
    const idx2 = await GitPackIndex.fromPack({
        pack: packfile2
    });

    return await toFiles(idx2, treeHash);
}

async function toFiles(idx, treeHash) {
    const tree = await idx.read({ oid: treeHash });
    readObject(tree);
    const files = {};
    for (const {path, oid, type} of tree.object) {
        if (type === 'blob') {
            const object = await idx.read({ oid });
            readObject(object);
            files[path] = new TextDecoder().decode(object.object);
            // files[path] = object.object;
            // files[path] = object.object.length;
        } else if (type === 'tree') {
            files[path] = await toFiles(idx, oid);
        }
    }
    return files;
}

function readObject(result) {
    if (!(result.object instanceof Buffer)) {
        return;
    }
    switch (result.type) {
        case 'commit':
            result.object = GitCommit.from(result.object).parse()
            break
        case 'tree':
            result.object = GitTree.from(result.object).entries()
            break
        case 'blob':
            result.object = new Uint8Array(result.object)
            result.format = 'content'
            break
        case 'tag':
            result.object = GitAnnotatedTag.from(result.object).parse()
            break
        default:
            throw new ObjectTypeError(
                result.oid,
                result.type,
                'blob|commit|tag|tree'
            )
    }
}
  

const corsProxy = 'http://127.0.0.1:8942';
// const repoUrl = 'https://github.com/wordpress/gutenberg';
// const paths = ['docs/tool'];

const repoUrl = 'https://gitlab.com/gitlab-org/gitlab.git';
const paths = ['changelogs'];

const corsUrl = corsProxy + '/' + repoUrl;

const GitRemoteHTTP = GitRemoteManager.getRemoteHelperFor({ url: corsUrl })
const ref = 'HEAD';

const refs = await fetchRefs(corsUrl, ref);
const objects = await fetchObjectHashes(corsUrl, refs[ref], paths);
const trees = await fetchTree(corsUrl, objects[paths[0]].oid);
console.log({ trees });
