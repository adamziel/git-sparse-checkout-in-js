const express = require('express');
const { createProxyMiddleware } = require('http-proxy-middleware');
const url = require('url');
const cors = require('cors')

const app = express();
const port = 8942;

app.use(cors());

// Middleware to handle CORS for all requests
app.use((req, res, next) => {
    res.header('Access-Control-Allow-Origin', '*');
    res.header('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    // Handle OPTIONS requests
    if (req.method === 'OPTIONS') {
        return res.sendStatus(200);
    }
    next();
});

// Middleware to extract and proxy the URL
app.use((req, res, next) => {
    // Extract the full URL including the protocol and host
    const fullUrl = `http://${req.headers.host}${req.originalUrl}`;
    // Parse the full URL to get the pathname and query
    const parsedUrl = url.parse(fullUrl);
     
    // Extract the target URL from the pathname
    let targetUrl = parsedUrl.path.substring(1); // Removing the leading "/"
    if (!targetUrl.startsWith('https://')) {
        targetUrl = 'https://' + targetUrl;
    }
    
    // Separate the base path and the query part
    let targetComponents = targetUrl.split('?');
    let basePath = targetComponents[0];
    let query = targetComponents[1] ? '?' + targetComponents[1] : '';

    // Create the full target URL
    targetUrl = basePath + query;
    const parsedTargetUrl = new URL(targetUrl);
    
    // Use the proxy middleware to forward the request
    if (targetUrl) {
        createProxyMiddleware({
            target: parsedTargetUrl.origin,
            changeOrigin: true,
            secure: true, // Set to true if the target uses HTTPS and you want to verify the SSL certificate
            pathRewrite: (path, req) => 
                path.replace(`/${parsedTargetUrl.origin}`, '')
        })(req, res, next);
    } else {
        res.status(400).send('Bad Request: Invalid Target URL');
    }
});

app.listen(port, () => {
    console.log(`CORS proxy is running at http://127.0.0.1:${port}`);
});
