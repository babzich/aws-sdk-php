<?php
/**
 * Copyright 2010-2012 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 * http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */

namespace Aws\S3;

use Aws\Common\Credentials\CredentialsInterface;
use Aws\Common\Enum\DateFormat;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\QueryString;

/**
 * Default Amazon S3 signature implementation
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/dev/RESTAuthentication.html
 */
class S3Signature implements S3SignatureInterface
{
    /**
     * @var array Query string values that must be signed
     */
    protected $signableQueryString = array(
        'acl', 'delete', 'lifecycle', 'location', 'logging', 'notification',
        'partNumber', 'policy', 'requestPayment', 'torrent', 'uploadId',
        'uploads', 'versionId', 'versioning', 'versions', 'website',
        'response-cache-control', 'response-content-disposition',
        'response-content-encoding', 'response-content-language',
        'response-content-type', 'response-expires', 'tagging', 'cors'
    );

    /**
     * @var array Sorted headers that must be signed
     */
    protected $signableHeaders = array('Content-MD5', 'Content-Type');

    /**
     * {@inheritdoc}
     */
    public function signRequest(RequestInterface $request, CredentialsInterface $credentials)
    {
        // Add the security token header if one is being used by the credentials
        if ($token = $credentials->getSecurityToken()) {
            $request->setHeader('x-amz-security-token', $token);
        }

        // Add a date header if one is not set
        if (!$request->hasHeader('date') && !$request->hasHeader('x-amz-date')) {
            $request->setHeader('Date', gmdate(DateFormat::RFC2822));
        }

        $stringToSign = $this->createCanonicalizedString($request);
        $request->getParams()->set('aws.string_to_sign', $stringToSign);

        $request->setHeader(
            'Authorization',
            'AWS ' . $credentials->getAccessKeyId() . ':' . $this->signString($stringToSign, $credentials)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function signString($string, CredentialsInterface $credentials)
    {
        return base64_encode(hash_hmac('sha1', $string, $credentials->getSecretKey(), true));
    }

    /**
     * {@inheritdoc}
     */
    public function createCanonicalizedString(RequestInterface $request, $expires = null)
    {
        $buffer = $request->getMethod() . "\n";

        // Add the interesting headers
        foreach ($this->signableHeaders as $header) {
            $buffer .= (string) $request->getHeader($header) . "\n";
        }

        // Choose dates from left to right based on what's set
        $date = $expires ?: (string) $request->getHeader('date');

        $buffer .= "{$date}\n"
            . $this->createCanonicalizedAmzHeaders($request)
            . $this->createCanonicalizedResource($request);

        return $buffer;
    }

    /**
     * Create a canonicalized AmzHeaders string for a signature.
     *
     * @param RequestInterface $request Request from which to gather headers
     *
     * @return string Returns canonicalized AMZ headers.
     */
    protected function createCanonicalizedAmzHeaders(RequestInterface $request)
    {
        $headers = array();
        foreach ($request->getHeaders(true) as $header) {
            /** @var $header \Guzzle\Http\Message\Header */
            $name = strtolower($header->getName());
            if (strpos($name, 'x-amz-') === 0) {
                $headers[$name] = $name . ':' . (string) $header;
            }
        }

        if (empty($headers)) {
            return '';
        } else {
            ksort($headers);
            return implode("\n", $headers) . "\n";
        }
    }

    /**
     * Create a canonicalized resource for a request
     *
     * @param RequestInterface $request Request for the resource
     *
     * @return string
     */
    protected function createCanonicalizedResource(RequestInterface $request)
    {
        // If this is a virtual hosted bucket, then append the bucket name
        $host = $request->getHost();

        $parts = explode('.', $host);
        $totalParts = count($parts);
        if ($parts[$totalParts - 2] !== 'amazonaws') {
            // The host does not contain S3, so it must be a CNAME
            $buffer = '/' . $host;
        } elseif ($totalParts > 3) {
            // The host contains more than three parts, so it is a virtual hosted bucket
            $buffer = '/' . implode('.', array_slice($parts, 0, -3));
        } else {
            // The host does not contain any bucket information
            $buffer = '';
        }

        $buffer .= $request->getPath();
        $buffer = str_replace('//', '/', $buffer);

        // Add sub resource parameters
        $query = $request->getQuery();
        $first = true;
        foreach ($this->signableQueryString as $key) {
            if ($value = $query->get($key)) {
                $buffer .= $first ? '?' : '&';
                $first = false;
                $buffer .= $key;
                if ($value !== QueryString::BLANK) {
                    $buffer .= "={$value}";
                }
            }
        }

        return $buffer;
    }
}
