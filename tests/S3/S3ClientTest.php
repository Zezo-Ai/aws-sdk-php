<?php
namespace Aws\Test\S3;

use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Identity\S3\S3ExpressIdentity;
use Aws\LruArrayCache;
use Aws\Endpoint\PartitionEndpointProvider;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\PermanentRedirectException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\RegionalEndpoint\Configuration;
use Aws\S3\S3Client;
use Aws\S3\UseArnRegion\Configuration as UseArnRegionConfiguration;
use Aws\Test\UsesServiceTrait;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Aws\Exception\UnresolvedEndpointException;

/**
 * @covers Aws\S3\S3Client
 * @covers Aws\S3\S3ClientTrait
 */
class S3ClientTest extends TestCase
{
    use UsesServiceTrait;
    const OPERATIONS_WITH_PARAMS_LOCATION = __DIR__ . '/fixtures/operations-with-params.json';

    public function testCanUseBucketEndpoint()
    {
        $c = new S3Client([
            'region'          => 'us-standard',
            'version'         => 'latest',
            'endpoint'        => 'http://test.domain.com',
            'bucket_endpoint' => true
        ]);
        $this->assertSame(
            'http://test.domain.com/key',
            $c->getObjectUrl('test', 'key')
        );
    }

    public function bucketNameProvider()
    {
        return [
            ['.bucket', false],
            ['bucket.', false],
            ['192.168.1.1', false],
            ['1.1.1.100', false],
            ['test@42!@$5_', false],
            ['ab', false],
            ['12', false],
            ['bucket_name', false],
            ['bucket-name', true],
            ['bucket', true],
            ['my.bucket.com', true],
            ['test-fooCaps', false],
            ['w-w', true],
            ['w------', false],
            ['', false],
            [null,false],
            [false,false],
            [true,false],
            [1,false],
            [[],false],
            [new \stdClass(),false]
        ];
    }

    /**
     * @dataProvider bucketNameProvider
     */
    public function testValidatesDnsBucketNames($bucket, $valid)
    {
        $this->assertEquals($valid, S3Client::isBucketDnsCompatible($bucket));
    }

    public function testCreatesPresignedRequests()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand('GetObject', ['Bucket' => 'foo', 'Key' => 'bar']);
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringStartsWith('https://foo.s3.amazonaws.com/bar?', $url);
        $this->assertStringContainsString('X-Amz-Expires=', $url);
        $this->assertStringContainsString('X-Amz-Credential=', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testCreatesPresignedRequestsWithAccessPointArn()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => 'arn:aws:s3:us-east-1:123456789012:accesspoint:myendpoint',
                'Key' => 'bar'
            ]
        );
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringStartsWith('https://myendpoint-123456789012.s3-accesspoint.us-east-1.amazonaws.com/bar?', $url);
        $this->assertStringContainsString('X-Amz-Expires=', $url);
        $this->assertStringContainsString('X-Amz-Credential=', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testCreatesPresignedRequestsWithStartTime()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand('GetObject', ['Bucket' => 'foo', 'Key' => 'bar']);
        $url = (string) $client->createPresignedRequest(
            $command,
            '+20 minutes',
            ['start_time' => 1562349366]
        )->getUri();
        $this->assertStringStartsWith('https://foo.s3.amazonaws.com/bar?', $url);
        $this->assertStringContainsString('X-Amz-Expires=1200', $url);
        $this->assertStringContainsString('X-Amz-Credential=', $url);
        $this->assertStringContainsString('X-Amz-Signature=61a9940ecdd901be8e36833f6d47123c0c719fc6aa82042144a6c5cf44a25988', $url);
    }

    public function testCreatesPresignedRequestsWithPathStyleFallback()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'foo.baz', 'secret' => 'bar']
        ]);
        $command = $client->getCommand('GetObject', ['Bucket' => 'foo.baz', 'Key' => 'bar']);
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringStartsWith('https://s3.amazonaws.com/foo.baz/bar?', $url);
        $this->assertStringContainsString('X-Amz-Expires=', $url);
        $this->assertStringContainsString('X-Amz-Credential=', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testCreatesPresignedRequestsWithPathStyle()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar'],
            'use_path_style_endpoint' => true
        ]);
        $command = $client->getCommand('GetObject', ['Bucket' => 'foo', 'Key' => 'bar']);
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringStartsWith('https://s3.amazonaws.com/foo/bar?', $url);
        $this->assertStringContainsString('X-Amz-Expires=', $url);
        $this->assertStringContainsString('X-Amz-Credential=', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testCreatingPresignedUrlDoesNotPermanentlyRemoveSigner()
    {
        $sent = false;
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'foo', 'secret'  => 'bar'],
            'http_handler' => function (RequestInterface $request) use (&$sent) {
                $sent = true;
                foreach (['X-Amz-Date', 'Authorization'] as $signatureHeader) {
                    $this->assertTrue($request->hasHeader($signatureHeader));
                }
                return Promise\Create::promiseFor(new Response);
            },
        ]);
        $command = $client->getCommand('GetObject', ['Bucket' => 'foo', 'Key' => 'bar']);
        $client->createPresignedRequest($command, 1342138769)->getUri();
        $client->execute($command);
        $this->assertTrue($sent);
    }

    public function testCreatesPresignedUrlsWithSpecialCharactersWithPathStyleFallback()
    {
        $client = new S3Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'foo', 'secret'  => 'bar']
        ]);
        $command = $client->getCommand('GetObject', [
            'Bucket' => 'foobar.test.abc',
            'Key'    => '+%.a'
        ]);
        $url = $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertSame('/foobar.test.abc/%2B%25.a', $url->getPath());
        $query = Psr7\Query::parse($url->getQuery());
        $this->assertArrayHasKey('X-Amz-Credential', $query);
        $this->assertArrayHasKey('X-Amz-Signature', $query);
    }

    public function testCreatesPresignedUrlsWithSpecialCharactersWithPathStyle()
    {
        $client = new S3Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'foo', 'secret'  => 'bar'],
            'use_path_style_endpoint' => true,
        ]);
        $command = $client->getCommand('GetObject', [
            'Bucket' => 'foobar.test.abc',
            'Key'    => '+%.a'
        ]);
        $url = $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertSame('/foobar.test.abc/%2B%25.a', $url->getPath());
        $query = Psr7\Query::parse($url->getQuery());
        $this->assertArrayHasKey('X-Amz-Credential', $query);
        $this->assertArrayHasKey('X-Amz-Signature', $query);
    }

    public function testCreatesPresignedRequestsForObjectLambdaService()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => 'arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/lambda-access-point',
                'Key' => 'bar'
            ]
        );
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringStartsWith('https://lambda-access-point-123456789012.s3-object-lambda.us-east-1.amazonaws.com/bar?', $url);
        $this->assertStringContainsString('X-Amz-Expires=', $url);
        $this->assertStringContainsString('X-Amz-Credential=', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testRegistersStreamWrapper()
    {
        $s3 = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $s3->registerStreamWrapper();
        $this->assertContains('s3', stream_get_wrappers());
        stream_wrapper_unregister('s3');
    }

    public function doesExistProvider()
    {
        $redirectException = new PermanentRedirectException(
            '',
            new Command('mockCommand'),
            ['response' => new Response(301)]
        );
        $deleteMarkerMock = $this->getS3ErrorMock('Foo', 404, true);

        return [
            ['foo', null, true, []],
            ['foo', 'bar', true, []],
            ['foo', null, true, $this->getS3ErrorMock('AccessDenied', 403)],
            ['foo', 'bar', true, $this->getS3ErrorMock('AccessDenied', 403)],
            ['foo', null, false, $this->getS3ErrorMock('Foo', 401)],
            ['foo', 'bar', false, $this->getS3ErrorMock('Foo', 401)],
            ['foo', null, -1, $this->getS3ErrorMock('Foo', 500)],
            ['foo', 'bar', -1, $this->getS3ErrorMock('Foo', 500)],
            ['foo', null, true, [], true],
            ['foo', 'bar', true, [] , true],
            ['foo', null, false, $this->getS3ErrorMock('Foo', 404), true],
            ['foo', 'bar', false, $this->getS3ErrorMock('Foo', 404), true],
            ['foo', null, -1, $this->getS3ErrorMock('Forbidden', 403), true],
            ['foo', 'bar', -1, $this->getS3ErrorMock('Forbidden', 403), true],
            ['foo', null, true, $this->getS3ErrorMock('Forbidden', 403), true, true],
            ['foo', 'bar', true, $deleteMarkerMock, true, false, true],
            ['foo', 'bar', false, $deleteMarkerMock, true, false, false],
            ['foo', null, true, $redirectException, true],
        ];
    }

    private function getS3ErrorMock(
        $errCode,
        $statusCode,
        $deleteMarker = false
    )
    {
        $response = new Response($statusCode);
        $deleteMarker && $response = $response->withHeader(
            'x-amz-delete-marker',
            'true'
        );

        $context = [
            'code' => $errCode,
            'response' => $response,
        ];
        return new S3Exception('', new Command('mockCommand'), $context);
    }

    /**
     * @dataProvider doesExistProvider
     */
    public function testsIfExists(
        $bucket,
        $key,
        $exists,
        $result,
        $V2 = false,
        $accept403 = false,
        $acceptDeleteMarkers = false
    )
    {
        /** @var S3Client $s3 */
        $s3 = $this->getTestClient('S3', ['region' => 'us-east-1']);
        $this->addMockResults($s3, [$result]);
        try {
            if ($V2) {
                if ($key) {
                    $this->assertSame($exists, $s3->doesObjectExistV2($bucket, $key, $acceptDeleteMarkers));
                } else {
                    $this->assertSame($exists, $s3->doesBucketExistV2($bucket, $accept403));
                }
            } else {
                if ($key) {
                    $this->assertSame($exists, $s3->doesObjectExist($bucket, $key));
                } else {
                    $this->assertSame($exists, $s3->doesBucketExist($bucket));
                }
            }
        } catch (\Exception $e) {
            $this->assertSame(-1, $exists);
        }
    }

    public function testReturnsObjectUrl()
    {
        $s3 = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => false
        ]);
        $this->assertSame(
            'https://foo.s3.amazonaws.com/bar',
            $s3->getObjectUrl('foo', 'bar')
        );
    }

    public function testReturnsObjectUrlWithPathStyleFallback()
    {
        $s3 = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => false,
        ]);
        $this->assertSame(
            'https://s3.amazonaws.com/foo.baz/bar',
            $s3->getObjectUrl('foo.baz', 'bar')
        );
    }

    public function testReturnsObjectUrlWithPathStyle()
    {
        $s3 = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => false,
            'use_path_style_endpoint' => true
        ]);
        $this->assertSame(
            'https://s3.amazonaws.com/foo/bar',
            $s3->getObjectUrl('foo', 'bar')
        );
    }

    public function testReturnsObjectUrlViaPath()
    {
        /** @var S3Client $s3 */
        $s3 = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => false
        ]);
        $this->assertSame(
            'https://foo.s3.amazonaws.com/bar',
            $s3->getObjectUrl('foo', 'bar')
        );
    }

    public function testReturnsObjectUrlViaPathWithPathStyleFallback()
    {
        /** @var S3Client $s3 */
        $s3 = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => false
        ]);
        $this->assertSame(
            'https://s3.amazonaws.com/foo.baz/bar',
            $s3->getObjectUrl('foo.baz', 'bar')
        );
    }

    public function testReturnsObjectUrlViaPathWithPathStyle()
    {
        /** @var S3Client $s3 */
        $s3 = $this->getTestClient('S3', [
            'region'      => 'us-east-1',
            'credentials' => false,
            'use_path_style_endpoint' => true
        ]);
        $this->assertSame(
            'https://s3.amazonaws.com/foo.baz/bar',
            $s3->getObjectUrl('foo.baz', 'bar')
        );
    }

    public function testEnsuresMandatoryInputVariables()
    {
        $this->expectExceptionMessage("The DeleteObject operation requires non-empty parameter: Bucket");
        $this->expectException(\InvalidArgumentException::class);
        /** @var S3Client $client */
        $client = $this->getTestClient('S3');
        $client->deleteObject([
            'Bucket' => "",
            'Key' => "key"]
        );
    }

    public function testEnsuresPrefixOrRegexSuppliedForDeleteMatchingObjects()
    {
        $this->expectException(\RuntimeException::class);
        /** @var S3Client $client */
        $client = $this->getTestClient('S3');
        $client->deleteMatchingObjects('foo');
    }

    public function testDeletesMatchingObjectsByPrefixAndRegex()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3');
        $client->getHandlerList()->setHandler(function ($c, $r) {
            $this->assertSame('bucket', $c['Bucket']);
            return Promise\Create::promiseFor(new Result([
                'IsTruncated' => false,
                'Marker' => '',
                'Contents' => [
                    ['Key' => 'foo/bar'],
                    ['Key' => 'foo/bar/baz'],
                    ['Key' => 'foo/test'],
                    ['Key' => 'foo/bar/bam'],
                    ['Key' => 'foo/bar/001'],
                    ['Key' => 'foo/other']
                ]
            ]));
        });

        $agg = [];
        $client->deleteMatchingObjects('bucket', 'foo/bar/', '/^foo\/bar\/[a-z]+$/', [
            'before' => function ($cmd) use (&$agg) {
                foreach ($cmd['Delete']['Objects'] as $k) {
                    $agg[] = $k['Key'];
                }
            }
        ]);

        $this->assertEquals(['foo/bar/baz', 'foo/bar/bam'], $agg);
    }

    public function testProxiesToTransferObjectPut()
    {
        $this->expectExceptionMessage("Mock queue is empty. Trying to send a PutObject");
        $this->expectException(\RuntimeException::class);
        $client = $this->getTestClient('S3');
        $client->uploadDirectory(__DIR__, 'test');
    }

    public function testProxiesToTransferObjectGet()
    {
        $this->expectExceptionMessage("Mock queue is empty. Trying to send a ListObjects");
        $this->expectException(\RuntimeException::class);
        $client = $this->getTestClient('S3');
        $client->downloadBucket(__DIR__, 'test');
    }

    public function testProxiesToObjectUpload()
    {
        $this->expectExceptionMessage("Mock queue is empty. Trying to send a PutObject");
        $this->expectException(\RuntimeException::class);
        $client = $this->getTestClient('S3');
        $client->upload('bucket', 'key', 'body');
    }

    public function testProxiesToObjectCopy()
    {
        $this->expectExceptionMessage("Mock queue is empty. Trying to send a HeadObject");
        $this->expectException(\RuntimeException::class);
        $client = $this->getTestClient('S3');
        $client->copy('from-bucket', 'fromKey', 'to-bucket', 'toKey');
    }

    /**
     * @dataProvider getTestCasesForLocationConstraints
     */
    public function testAddsLocationConstraintAutomatically($region, $target, $command, $contains)
    {
        $client = $this->getTestClient('S3', ['region' => $region]);
        $params = [
            'Bucket' => 'foo',
            'CreateBucketConfiguration' => [
                'Bucket' => [
                    'Type' => 'foo'
                ]
            ]
        ];
        if ($region !== $target) {
            $params['CreateBucketConfiguration']['LocationConstraint'] = $target;
        }
        $command = $client->getCommand($command, $params);

        $text = "<LocationConstraint>{$target}</LocationConstraint>";
        $body = (string) \Aws\serialize($command)->getBody();
        if ($contains) {
            $this->assertStringContainsString($text, $body);
        } else {
            $this->assertStringNotContainsString($text, $body);
        }
        //ensures original configuration is not overwritten
        if ($target !== 'us-east-1'
            && $command->getName() === 'CreateBucket'
        ) {
            $this->assertStringContainsString('<Bucket><Type>foo</Type></Bucket>', $body);
        }
    }

    public function getTestCasesForLocationConstraints()
    {
        return [
            ['us-west-2', 'us-west-2', 'CreateBucket', true],
            ['us-east-1', 'us-east-1', 'CreateBucket', false],
            ['us-west-2', 'us-west-2', 'HeadBucket',   false],
            ['us-west-2', 'eu-west-1', 'CreateBucket', true],
            ['us-west-2', 'us-east-1', 'CreateBucket', false],
        ];
    }

    /**
     * @param string $bucket
     *
     * @dataProvider directoryBucketLocationConstraintProvider
     */
    public function testDoesNotAddLocationConstraintForDirectoryBuckets(
        string $bucket
    ): void
    {
        $client = $this->getTestClient('s3');
        $params = ['Bucket' => $bucket];
        $command = $client->getCommand('CreateBucket', $params);
        $body = (string) \Aws\serialize($command)->getBody();
        $this->assertStringNotContainsString('LocationConstraint', $body);
    }

    public function directoryBucketLocationConstraintProvider(): array
    {
        return [
            ['bucket-base-name--usw2-az1--x-s3'],
            ['mybucket123--euw1-az2--x-s3'],
            ['test_bucket_name--apne1-az3--x-s3'],
            ['valid-name--aps1-az4--x-s3'],
            ['s3_express_demo_directory_bucket--usw2-az1--x-s3']
        ];
    }

    public function testSaveAsParamAddsSink()
    {
        $client = $this->getTestClient('S3', [
            'http_handler' => function ($request, array $options) {
                $this->assertArrayHasKey('sink', $options);
                return Promise\Create::promiseFor(
                    new Psr7\Response(200, [], 'sink=' . $options['sink'])
                );
            }
        ]);

        $result = $client->getObject([
            'Bucket' => 'foo',
            'Key'    => 'bar',
            'SaveAs' => 'baz',
        ]);

        $this->assertSame('sink=baz', (string) $result['Body']);
    }

    public function testRequestSucceedsWithColon()
    {
        $key = 'aaa:bbb';
        $s3 = $this->getTestClient('S3', [
            'http_handler' => function (RequestInterface $request) use ($key) {
                $this->assertStringContainsString(
                    urlencode($key),
                    (string) $request->getUri()
                );

                return Promise\Create::promiseFor(new Psr7\Response);
            }
        ]);

        $s3->getObject([
            'Bucket' => 'bucket',
            'Key'    => $key,
        ]);
    }

    /**
     * @dataProvider clientRetrySettingsProvider
     *
     * @param array $retrySettings
     */
    public function testRetriesConnectionErrors($retrySettings)
    {
        $retries = $retrySettings['max_attempts'] - 1;
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'retries' => $retrySettings,
            'http_handler' => function () use (&$retries) {
                if (0 === --$retries) {
                    return new FulfilledPromise(new Response);
                }

                return new RejectedPromise([
                    'connection_error' => true,
                    'exception' => $this->getMockBuilder(ConnectException::class)
                        ->disableOriginalConstructor()
                        ->getMock(),
                    'response' => null,
                ]);
            },
        ]);

        $client->headBucket([
            'Bucket' => 'bucket',
        ]);

        $this->assertSame(0, $retries);
    }

    public function clientRetrySettingsProvider()
    {
        return [
            [
                [
                    'mode' => 'legacy',
                    'max_attempts' => 11,
                ],
            ],
            [
                [
                    'mode' => 'standard',
                    'max_attempts' => 11,
                ],
            ],
            [
                [
                    'mode' => 'adaptive',
                    'max_attempts' => 11,
                ],
            ],
        ];
    }

    /**
     * @dataProvider s3OperationsProvider
     *
     * @param string   $operation
     * @param array    $payload
     * @param array    $retryOptions
     */
    public function testRetries200Errors(
        string $operation,
        array $payload,
        array $retryOptions
    ) {
        $retries = $retryOptions['max_attempts'];
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'retries' => $retryOptions,
            'http_handler' => function () use (&$retries) {
                if (0 === --$retries) {
                    return new FulfilledPromise(new Response(
                        200,
                        [],
                        $this->getWellFormedXml()
                    ));
                }

                return new FulfilledPromise(new Response(200, [], $this->getErrorXml()));
            },
        ]);

        $client->{$operation}($payload);

        $this->assertSame(0, $retries);
    }

    /**
     * This provider returns a set of s3 operations along with
     * the required params, and a retry configuration.
     *
     * @return \Generator
     */
    public function s3OperationsProvider(): \Generator
    {
        $operations = $this->loadOperations();
        $retryModes = [
            'legacy',
            'standard',
            'adaptive'
        ];

        foreach ($operations as $operation) {
            foreach ($retryModes as $retryMode) {
                yield ($operation['operation'] . '/' . $retryMode) => [
                    $operation['operation'],
                    $operation['params'],
                    [
                        'mode' => $retryMode,
                        'max_attempts' => 5
                    ],
                ];
            }
        }
    }

    /**
     * Load a list of s3 operations along with the required params populated.
     *
     * @return array
     */
    private function loadOperations(): array
    {
        $jsonContent = file_get_contents(self::OPERATIONS_WITH_PARAMS_LOCATION);

        return json_decode($jsonContent, true);
    }

    private function getErrorXml()
    {
        return <<<EOXML
<?xml version="1.0" encoding="UTF-8"?>
<Error>
  <Code>InternalError</Code>
  <Message>We encountered an internal error. Please try again.</Message>
  <RequestId>656c76696e6727732072657175657374</RequestId>
  <HostId>Uuag1LuByRx9e6j5Onimru9pO4ZVKnJ2Qz7/C1NPcfTWAtRPfTaOFg==</HostId>
</Error>
EOXML;
    }

    private function getMalformedXml()
    {
        return <<<EOXML
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
    <Name>jmtestbucket2</Name>
    <Prefix></Prefix>
    <Marker></Marker>
    <MaxKeys>1000</MaxKeys>
    <Delimiter>/</Delimiter>
    <IsTruncated>false</IsTruncated>
    <Contents>
        <Key>&lt;</Key>
        <LastModified>2015-09-03T23:51:29.000Z</LastModified>
        <ETag>&quot;af1ed9909386b6116bda14403ff5f72e&quot;</ETag>
        <Size>10</Size>
EOXML;
    }

    private function getWellFormedXml()
    {
        return '<?xml version="1.0" encoding="UTF-8"?><node></node>';
    }

    /**
     * @dataProvider clientRetrySettingsProvider
     *
     * @param array $retrySettings
     */
    public function testClientSocketTimeoutErrorsAreNotRetriedIndefinitely($retrySettings)
    {
        $this->expectExceptionMessageMatches("/Your socket connection to the server/");
        $this->expectException(\Aws\S3\Exception\S3Exception::class);
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'retries' => $retrySettings,
            'http_handler' => function () {
                return new RejectedPromise([
                    'connection_error' => false,
                    'exception' => $this->getMockBuilder(RequestException::class)
                        ->disableOriginalConstructor()
                        ->getMock(),
                    'response' => new Response(400, [], $this->getSocketTimeoutResponse()),
                ]);
            },
        ]);

        $client->putObject([
            'Bucket' => 'bucket',
            'Key' => 'key',
            'Body' => Psr7\Utils::streamFor('x'),
        ]);
    }

    private function getSocketTimeoutResponse()
    {
        return <<<EOXML
<?xml version="1.0" encoding="UTF-8"?>
<Error>
    <Code>RequestTimeout</Code>
    <Message>Your socket connection to the server was not read from or written to within the timeout period. Idle connections will be closed.</Message>
    <RequestId>REQUEST_ID</RequestId>
    <HostId>HOST_ID</HostId>
</Error>
EOXML;
    }

    /**
     * @dataProvider clientRetrySettingsProvider
     *
     * @param array $retrySettings
     */
    public function testNetworkingErrorsAreRetriedOnIdempotentCommands($retrySettings)
    {
        $networkingError = $this->getMockBuilder(RequestException::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $retries = $retrySettings['max_attempts'] - 1;
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'retries' => $retrySettings,
            'http_handler' => function () use (&$retries, $networkingError) {
                if (0 === --$retries) {
                    return new FulfilledPromise(new Response);
                }

                return new RejectedPromise([
                    'connection_error' => false,
                    'exception' => $networkingError,
                    'response' => null,
                ]);
            },
        ]);

        $client->putObject([
            'Bucket' => 'bucket',
            'Key' => 'key',
        ]);

        $this->assertSame(0, $retries);
    }

    /**
     * @dataProvider clientRetrySettingsProvider
     *
     * @param array $retrySettings
     */
    public function testNetworkingErrorsAreNotRetriedOnNonIdempotentCommands($retrySettings)
    {
        $this->expectExceptionMessageMatches("/CompleteMultipartUpload/");
        $this->expectException(\Aws\S3\Exception\S3Exception::class);
        $networkingError = $this->getMockBuilder(RequestException::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $retries = $retrySettings['max_attempts'];
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'retries' => $retrySettings,
            'http_handler' => function () use (&$retries, $networkingError) {
                if (0 === --$retries) {
                    return new FulfilledPromise(new Response);
                }

                return new RejectedPromise([
                    'connection_error' => false,
                    'exception' => $networkingError,
                    'response' => null,
                ]);
            },
        ]);

        $client->completeMultipartUpload([
            'Bucket' => 'bucket',
            'Key' => 'key',
            'UploadId' => 1,
        ]);

        $this->assertSame(0, $retries);
    }

    /**
     * @dataProvider clientRetrySettingsProvider
     *
     * @param array $retrySettings
     */
    public function testErrorsWithUnparseableBodiesCanBeRetried($retrySettings)
    {
        $networkingError = $this->getMockBuilder(RequestException::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMock();

        $retries = $retrySettings['max_attempts'];
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'retries' => $retrySettings,
            'http_handler' => function () use (&$retries, $networkingError) {
                if (0 === --$retries) {
                    return new FulfilledPromise(new Response);
                }

                return new RejectedPromise([
                    'connection_error' => false,
                    'exception' => $networkingError,
                    'response' => new Response(200, [], openssl_random_pseudo_bytes(2048)),
                ]);
            },
        ]);

        $client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'key',
        ]);

        $this->assertSame(0, $retries);
    }

    /**
     * @dataProvider  clientRetrySettingsProvider
     * @param $retrySettings
     */
    public function testRetriesFailOn400Errors($retrySettings) {
        $retryCount = 0;
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'retries' => $retrySettings,
            'http_handler' => function () use (&$retryCount) {
                $retryCount++;
                return new RejectedPromise([
                    'connection_error' => false,
                    'exception' => $this->getMockBuilder(S3Exception::class)
                        ->disableOriginalConstructor()
                        ->getMock(),
                    'response' => new Response(404, [], null),
                ]);
            },
        ]);
        $client->getObjectAsync([
            'Bucket' => 'bucket',
            'Key' => 'key'
        ])->otherwise(function () {})->wait();

        $this->assertSame(1, $retryCount);
    }

    public function testListObjectsAppliesUrlEncodingWhenNoneSupplied()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => function (RequestInterface $request) {
                $query = Psr7\Query::parse($request->getUri()->getQuery());
                $this->assertArrayHasKey('encoding-type', $query);
                $this->assertSame('url', $query['encoding-type']);

                return new FulfilledPromise(new Response);
            },
        ]);

        $client->listObjects(['Bucket' => 'bucket']);
    }

    public function testListObjectsUrlDecodesEncodedKeysWhenEncodingNotSupplied()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => function () {
                return new FulfilledPromise(new Response(200, [], $this->getUrlEncodedListObjectsResponse()));
            },
        ]);

        $response = $client->listObjects(['Bucket' => 'bucket']);
        $this->assertSame(',', $response['Delimiter']);
        $this->assertSame('test/yearmonth=201601/file2', $response['Marker']);
        $this->assertSame('test/yearmonth=201601/file2', $response['NextMarker']);
        $this->assertSame('test/yearmonth=201601/', $response['Prefix']);
        $this->assertSame('test/yearmonth=201601/file1', $response['Contents'][0]['Key']);
        $this->assertSame('test/yearmonth=201601/', $response['CommonPrefixes'][0]['Prefix']);
    }

    public function testListObjectsDefaultEncodingDoesNotCreateReferences()
    {
        $listObjects = $this->getUrlEncodedListObjectsResponse();
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => function () use ($listObjects) {
                return new FulfilledPromise(new Response(200, [], $listObjects));
            },
        ]);

        $response = $client->listObjects(['Bucket' => 'bucket']);
        $this->assertSame('test/yearmonth=201601/file1', $response['Contents'][0]['Key']);
        $this->assertSame('test/yearmonth=201601/', $response['CommonPrefixes'][0]['Prefix']);

        $listObjectsCopy = $listObjects;
        $listObjectsCopy = str_replace('file1', 'thisisatest', $listObjectsCopy);

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => function () use ($listObjects) {
                return new FulfilledPromise(new Response(200, [], $listObjects));
            },
        ]);
        $response = $client->listObjects(['Bucket' => 'bucket']);
        $this->assertSame('test/yearmonth=201601/file1', $response['Contents'][0]['Key']);
        $this->assertSame('test/yearmonth=201601/', $response['CommonPrefixes'][0]['Prefix']);
    }

    public function testListObjectsDoesNotUrlDecodeEncodedKeysWhenEncodingSupplied()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => function () {
                return new FulfilledPromise(new Response(200, [], $this->getUrlEncodedListObjectsResponse()));
            },
        ]);

        $response = $client->listObjects([
            'Bucket' => 'bucket',
            'EncodingType' => 'url',
        ]);

        $this->assertSame('%2C', $response['Delimiter']);
        $this->assertSame('test/yearmonth%3D201601/file2', $response['Marker']);
        $this->assertSame('test/yearmonth%3D201601/file2', $response['NextMarker']);
        $this->assertSame('test/yearmonth%3D201601/', $response['Prefix']);
        $this->assertSame('test/yearmonth%3D201601/file1', $response['Contents'][0]['Key']);
        $this->assertSame('test/yearmonth%3D201601/', $response['CommonPrefixes'][0]['Prefix']);
    }

    private function getUrlEncodedListObjectsResponse()
    {
        return <<<EOXML
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
    <Prefix>test/yearmonth%3D201601/</Prefix>
    <Marker>test/yearmonth%3D201601/file2</Marker>
    <NextMarker>test/yearmonth%3D201601/file2</NextMarker>
    <Delimiter>%2C</Delimiter>
    <EncodingType>url</EncodingType>
    <Contents>
        <Key>test/yearmonth%3D201601/file1</Key>
    </Contents>
    <CommonPrefixes>
        <Prefix>test/yearmonth%3D201601/</Prefix>
    </CommonPrefixes>
</ListBucketResult>
EOXML;
    }

    public function testHeadObjectDisablesContentDecodingByDefault()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => function (RequestInterface $r, array $opts = []) {
                $this->assertArrayHasKey('decode_content', $opts);
                $this->assertFalse($opts['decode_content']);

                return Promise\Create::promiseFor(new Response);
            }
        ]);

        $client->headObject(['Bucket' => 'bucket', 'Key' => 'key']);
    }

    public function testContentDecodingCanBeDisabled()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http' => ['decode_content' => false],
            'http_handler' => function (RequestInterface $r, array $opts = []) {
                $this->assertArrayHasKey('decode_content', $opts);
                $this->assertFalse($opts['decode_content']);

                return Promise\Create::promiseFor(new Response);
            }
        ]);

        $client->getObject(['Bucket' => 'bucket', 'Key' => 'key']);
    }

    public function testContentDecodingCanBeDisabledOnCommands()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => function (RequestInterface $r, array $opts = []) {
                $this->assertArrayHasKey('decode_content', $opts);
                $this->assertFalse($opts['decode_content']);

                return Promise\Create::promiseFor(new Response);
            }
        ]);

        $client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'key',
            '@http' => ['decode_content' => false],
        ]);
    }

    public function testS3ExpressAuth()
    {
        $client = new S3Client([
            'region' => 'us-east-1',
            's3_express_identity_provider' => function ($command) {
                $identity = new S3ExpressIdentity(
                    'foo',
                    'bar',
                    'baz',
                     time() + 4000
                );
                return Promise\Create::promiseFor($identity);
            },
            'http_handler' => function (RequestInterface $r) {
                $this->assertSame('baz', $r->getHeaderLine('x-amz-s3session-token'));
                $this->assertEmpty($r->getHeaderLine('X-Amz-Security-Token'));
                return Promise\Create::promiseFor(new Response);
            }
        ]);
        $client->getHandlerList()->appendBuild(
            Middleware::mapRequest(function (RequestInterface $request) {
                return $request->withHeader('X-Amz-Security-Token', 'Bar');
            }),
            'add-security-token'
        );
        $client->getObject(['Bucket' => 'bucket--use1-az2--x-s3', 'Key' => 'key']);
    }

    public function testS3ExpressAuthCanBeDisabled()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'disable_express_session_auth' => true,
            'http_handler' => function (RequestInterface $r, array $opts = []) {
                $this->assertEmpty($r->getHeaderLine('x-amz-s3session-token'));
                $this->assertSame('Bar', $r->getHeaderLine('X-Amz-Security-Token'));
                return Promise\Create::promiseFor(new Response);
            }
        ]);
        $client->getHandlerList()->appendBuild(
            Middleware::mapRequest(function (RequestInterface $request) {
                return $request->withHeader('X-Amz-Security-Token', 'Bar');
            }),
            'add-security-token'
        );
        $client->getObject(['Bucket' => 'bucket--use1-az2--x-s3', 'Key' => 'key']);
    }

    public function testS3ExpressDisabledPresignedUrl()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'disable_express_session_auth' => true,
        ]);
        $client->getHandlerList()->appendBuild(
            Middleware::mapRequest(function (RequestInterface $request) {
                return $request->withHeader('X-Amz-Security-Token', 'Bar');
            }),
            'add-security-token'
        );
        $command = $client->getCommand('GetObject', ['Bucket' => 'bucket--use1-az2--x-s3',
            'Key' => 'key']);
        $request = $client->createPresignedRequest($command, '+20 minutes');
        $url = (string)$request->getUri();
        $this->assertStringNotContainsString('x-amz-s3session-token=baz', $url);
        $this->assertStringContainsString('X-Amz-Security-Token', $url);
    }

    public function testS3ExpressPresignedUrl()
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            's3_express_identity_provider' => function ($command) {
                $identity = new S3ExpressIdentity(
                    'foo',
                    'bar',
                    'baz',
                    time() + 4000
                );
                return Promise\Create::promiseFor($identity);
            },
        ]);
        $client->getHandlerList()->appendBuild(
            Middleware::mapRequest(function (RequestInterface $request) {
                return $request->withHeader('X-Amz-Security-Token', 'Bar');
            }),
            'add-security-token'
        );
        $command = $client->getCommand('GetObject', ['Bucket' => 'bucket--use1-az2--x-s3',
            'Key' => 'key']);
        $request = $client->createPresignedRequest($command, '+20 minutes');
        $url = (string)$request->getUri();
        $this->assertStringContainsString('x-amz-s3session-token=baz', $url);
        $this->assertStringNotContainsString('X-Amz-Security-Token', $url);
    }

    public function testCanDetermineRegionOfBucket()
    {
        $client = new S3Client([
            'region' => 'us-west-2',
            'version' => 'latest',
            'http_handler' => function () {
                return new FulfilledPromise(new Response(301, [
                    'X-Amz-Bucket-Region' => 'alderaan-north-1',
                ]));
            },
        ]);
        $this->assertSame('alderaan-north-1', $client->determineBucketRegion('bucket'));

        $client = new S3Client([
            'region' => 'us-west-2',
            'version' => 'latest',
            'http_handler' => function() {
                return new RejectedPromise([
                    'connection_error' => false,
                    'exception' => $this->getMockBuilder(AwsException::class)
                        ->disableOriginalConstructor()
                        ->getMock(),
                    'response' => new Response(400, [
                        'X-Amz-Bucket-Region' => 'us-west-2',
                    ]),
                ]);
            },
        ]);
        $this->assertSame('us-west-2', $client->determineBucketRegion('bucket'));
    }

    public function testDetermineBucketRegionExposeException()
    {
        $this->expectException(\Aws\Exception\AwsException::class);
        $client = new S3Client([
            'region' => 'us-west-2',
            'version' => 'latest',
            'http_handler' => function() {
                return new RejectedPromise([
                    'connection_error' => false,
                    'exception' => $this->getMockBuilder(AwsException::class)
                        ->disableOriginalConstructor()
                        ->getMock(),
                    'response' => null,
                ]);
            },
        ]);
        $client->determineBucketRegion('bucket');
    }

    public function testAppliesS3EndpointMiddlewareDualstackAccelerate()
    {
        // test applies s3-accelerate.dualstack for valid operations
        $handler = function (RequestInterface $req) {
            $this->assertSame(
                'bucket.s3-accelerate.dualstack.amazonaws.com',
                $req->getUri()->getHost()
            );
            $this->assertSame(
                '/key',
                $req->getUri()->getPath()
            );
            return Promise\Create::promiseFor(new Response);
        };

        $accelerateClient = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'use_accelerate_endpoint' => true,
            'use_dual_stack_endpoint' => true,
            'http_handler' => $handler,
        ]);
        $accelerateClient->getObject([
            'Bucket' => 'bucket',
            'Key' => 'key'
        ]);

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => $handler,
        ]);
        $client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'key',
            '@use_accelerate_endpoint' => true,
            '@use_dual_stack_endpoint' => true,
        ]);
    }

    public function testAppliesS3EndpointMiddlewareAccelerate()
    {
        // test applies s3-accelerate for valid operations
        $handler = function (RequestInterface $req) {
            $this->assertSame(
                'bucket.s3-accelerate.amazonaws.com',
                $req->getUri()->getHost()
            );
            return Promise\Create::promiseFor(new Response);
        };

        $accelerateClient = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'use_accelerate_endpoint' => true,
            'http_handler' => $handler,
        ]);
        $accelerateClient->getObject([
            'Bucket' => 'bucket',
            'Key' => 'key'
        ]);

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => $handler,
        ]);
        $client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'key',
            '@use_accelerate_endpoint' => true,
        ]);
    }

    public function testAppliesS3EndpointMiddlewareDualstack()
    {
        // test applies dualstack
        $handler = function (RequestInterface $req) {
            $this->assertSame(
                'bucket.s3.dualstack.us-west-2.amazonaws.com',
                $req->getUri()->getHost()
            );
            $this->assertSame(
                '/',
                $req->getUri()->getPath()
            );
            return Promise\Create::promiseFor(new Response);
        };

        $dualStackClient = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'use_dual_stack_endpoint' => true,
            'http_handler' => $handler,
        ]);
        $dualStackClient->createBucket([
            'Bucket' => 'bucket',
        ]);

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => $handler,
        ]);
        $client->createBucket([
            'Bucket' => 'bucket',
            '@use_dual_stack_endpoint' => true,
        ]);
    }

    public function testAppliesS3EndpointMiddlewareDualstackWithPathStyle()
    {
        // test applies dualstack with path style
        $handler = function (RequestInterface $req) {
            $this->assertSame(
                's3.dualstack.us-west-2.amazonaws.com',
                $req->getUri()->getHost()
            );
            $this->assertSame(
                '/bucket',
                $req->getUri()->getPath()
            );
            return Promise\Create::promiseFor(new Response);
        };

        $dualStackClient = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'use_dual_stack_endpoint' => true,
            'use_path_style_endpoint' => true,
            'http_handler' => $handler,
        ]);
        $dualStackClient->createBucket([
            'Bucket' => 'bucket',
        ]);

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-west-2',
            'http_handler' => $handler,
        ]);
        $client->createBucket([
            'Bucket' => 'bucket',
            '@use_dual_stack_endpoint' => true,
            '@use_path_style_endpoint' => true,
        ]);
    }

    public function testAddsUseArnRegionArgument()
    {
        $this->expectExceptionMessage("Invalid configuration value provided for \"use_arn_region\"");
        $this->expectException(\InvalidArgumentException::class);
        new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'use_arn_region' => 'trigger exception'
        ]);
    }

    public function testAddsUseArnRegionCacheArgument()
    {
        // Create cache object
        $cache = new LruArrayCache();
        $cache->set('aws_s3_use_arn_region_config', new UseArnRegionConfiguration(true));

        // Create client using cached use_arn_region config
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'use_arn_region' => $cache,
            'handler' => function (CommandInterface $cmd, RequestInterface $req) {
                $this->assertSame(
                    'myendpoint-123456789012.s3-accesspoint.us-west-2.amazonaws.com',
                    $req->getUri()->getHost()
                );
                $this->assertSame(
                    '/Bar/Baz',
                    $req->getUri()->getPath()
                );
                return new Result([]);
            },
        ]);

        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => 'arn:aws:s3:us-west-2:123456789012:accesspoint:myendpoint',
                'Key' => 'Bar/Baz',
            ]
        );
        $client->execute($command);
    }

    public function testCopyOperationCorrectlyPopulates()
    {
        $client = new S3Client([
            'region' => 'us-west-2',
            'version' => 'latest',
            'handler' => function (CommandInterface $cmd, RequestInterface $req) {
                $this->assertSame(
                    'myendpoint-123456789012.s3-accesspoint.us-west-2.amazonaws.com',
                    $req->getUri()->getHost()
                );
                $this->assertSame(
                    '/copied-object',
                    $req->getUri()->getPath()
                );
                $this->assertSame(
                    'arn:aws:s3:us-west-2:1234567890123:accesspoint:my-my/finks-object',
                    $req->getHeader('x-amz-copy-source')[0]
                );
                return new Result([]);
            },
        ]);

        $command = $client->getCommand(
            'CopyObject',
            [
                'Bucket' => 'arn:aws:s3:us-west-2:123456789012:accesspoint:myendpoint',
                'Key' => 'copied-object',
                'CopySource' => 'arn:aws:s3:us-west-2:1234567890123:accesspoint:my-my/finks-object'
            ]
        );
        $client->execute($command);
    }

    public function testAddsS3RegionalEndpointArgument()
    {
        $this->expectExceptionMessage("Configuration parameter must either be 'legacy' or 'regional'.");
        $this->expectException(\InvalidArgumentException::class);
        new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            's3_us_east_1_regional_endpoint' => 'trigger_exception'
        ]);
    }

    public function testAddsS3RegionalEndpointsCacheArgument()
    {
        // Create cache object
        $cache = new LruArrayCache();
        $cache->set('aws_s3_us_east_1_regional_endpoint_config', new Configuration('regional'));
        // Create client using cached endpoints config
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            's3_us_east_1_regional_endpoint' => $cache
        ]);
        // Get the expected Uri from the PartitionEndpointProvider
        $provider = PartitionEndpointProvider::defaultProvider([
            's3_us_east_1_regional_endpoint' => 'regional'
        ]);
        $endpoint = $provider([
            'service' => 's3',
            'region' => 'us-east-1',
        ]);
        $uri = new Uri($endpoint['endpoint']);
        $this->assertSame($uri->getHost(), $client->getEndpoint()->getHost());
    }

    /**
     * Tests that S3 client configuration options lead to correct endpoints
     *
     * @dataProvider optionsToEndpointsCases
     * @param $options
     * @param $host
     */
    public function testResolvesOptionsToProperEndpoints($options, $host)
    {
        $client = new S3Client($options);
        $client->getHandlerList()->appendSign(
            Middleware::tap(function ($cmd, $req) use ($host) {
                $this->assertEquals($host, $req->getUri()->getHost());
            })
        );
        $client->listBuckets();
    }

    public function optionsToEndpointsCases()
    {
        $handler = function ($cmd, $req) {
            return Promise\Create::promiseFor(new Result([]));
        };
        $data = json_decode(
            file_get_contents(__DIR__ . '/../Endpoint/fixtures/s3_us_east_1_regional_endpoint.json'),
            true
        );

        return [
            [
                [
                    'region' => 'us-east-1',
                    'version' => 'latest',
                    'handler' => $handler,
                ],
                's3.amazonaws.com'
            ],
            [
                [
                    'region' => 'us-east-1',
                    'version' => 'latest',
                    'handler' => $handler,
                    's3_us_east_1_regional_endpoint' => 'regional'
                ],
                's3.us-east-1.amazonaws.com'
            ],
            [
                [
                    'region' => 'us-west-2',
                    'version' => 'latest',
                    'handler' => $handler,
                ],
                's3.us-west-2.amazonaws.com'
            ],
            [
                [
                    'region' => 'us-west-2',
                    'version' => 'latest',
                    'handler' => $handler,
                ],
                's3.us-west-2.amazonaws.com'
            ],
            [
                [
                    'region' => 'us-east-1',
                    'version' => 'latest',
                    'handler' => $handler,
                    'use_dual_stack_endpoint' => true,
                ],
                's3.dualstack.us-east-1.amazonaws.com'
            ],
            [
                [
                    'region' => 'us-east-1',
                    'version' => 'latest',
                    'handler' => $handler,
                    'use_dual_stack_endpoint' => true,
                ],
                's3.dualstack.us-east-1.amazonaws.com'
            ],
        ];
    }

    public function testAppliesAmbiguousSuccessParsing()
    {
        $this->expectExceptionMessage("Error parsing response for CopyObject: AWS parsing error: Error parsing XML: String could not be parsed as XML");
        $this->expectException(\Aws\S3\Exception\S3Exception::class);
        $httpHandler = function ($request, array $options) {
            return Promise\Create::promiseFor(
                new Psr7\Response(200, [], "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\n\n")
            );
        };

        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => 'us-east-1',
            'http_handler' => $httpHandler
        ]);

        $s3->copyObject([
            'Bucket' => 'test-dest',
            'Key' => 'test-key',
            'CopySource' => 'test-source/key'
        ]);
    }

    public function testRetriesAmbiguousSuccesses()
    {
        $counter = 0;
        $httpHandler = function ($request, array $options) use (&$counter) {
            if ($counter < 2) {
                $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n\n\n";
            } else {
                $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><OperationNameResponse><UploadId>baz</UploadId></OperationNameResponse>";
            }
            $counter++;

            return Promise\Create::promiseFor(
                new Psr7\Response(200, [], $body)
            );
        };

        $s3 = new S3Client([
            'version'     => 'latest',
            'region'      => 'us-east-1',
            'http_handler' => $httpHandler
        ]);
        $s3->copyObject([
            'Bucket' => 'test-dest',
            'Key' => 'test-key',
            'CopySource' => 'test-source/key'
        ]);

        $this->assertSame(3, $counter);
    }

    public function multiRegionSuccessProvider()
    {

        return [
            ["arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "us-east-1", null, null, false, "mfzwi23gnjvgw.mrap.accesspoint.s3-global.amazonaws.com", "x-amz-region-set:*"],
            ["arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "us-west-2", null, null, false, "mfzwi23gnjvgw.mrap.accesspoint.s3-global.amazonaws.com", "x-amz-region-set:*"],
            ["arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "aws-global", null, null, false, "mfzwi23gnjvgw.mrap.accesspoint.s3-global.amazonaws.com", "x-amz-region-set:*"],
            ["arn:aws-cn:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "cn-north-1", null, null, false, "mfzwi23gnjvgw.mrap.accesspoint.s3-global.amazonaws.com.cn", "x-amz-region-set:*"],
            ["arn:aws:s3::123456789012:accesspoint:myendpoint", "us-west-2", null, null, false, "myendpoint.accesspoint.s3-global.amazonaws.com", "x-amz-region-set:*"],
            ["arn:aws:s3::123456789012:accesspoint:my.bucket", "us-west-2", null, null, false, "my.bucket.accesspoint.s3-global.amazonaws.com", "x-amz-region-set:*"],
        ];
    }

    /**
     * @dataProvider multiRegionSuccessProvider
     */
    public function testMrapParsing(
        $bucketFieldInput,
        $clientRegion,
        $additionalFlags,
        $useArnRegion,
        $disableMraps,
        $expectedEndpoint,
        $expectedHeaders
    ) {
        if (!extension_loaded('awscrt')) {
            $this->markTestSkipped();
        }
        $client = new S3Client([
            'region' => $clientRegion,
            'use_arn_region' => $useArnRegion,
            'version' => 'latest',
            'disable_multiregion_access_points' => $disableMraps,
            'handler' => function (CommandInterface $cmd, RequestInterface $req)
            use ($expectedEndpoint, $expectedHeaders) {
                $this->assertSame(
                    $expectedEndpoint,
                    $req->getUri()->getHost()
                );
                $this->assertSame(
                    '/Bar/Baz',
                    $req->getUri()->getPath()
                );
                $expectedHeaders = explode(',', $expectedHeaders);
                foreach ($expectedHeaders as $expectedHeader) {
                    $header = explode(':', $expectedHeader);
                    $this->assertSame($header[1], $req->getHeader($header[0])[0]);
                }

                return new Result([]);
            },
        ]);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => $bucketFieldInput,
                'Key' => 'Bar/Baz',
            ]
        );
        $client->execute($command);
    }

    public function mrapExceptionTestProvider() {
        return [
            [
                "arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "us-west-2", null, null, true,
                "Invalid configuration: Multi-Region Access Point ARNs are disabled."
            ],
            [
                "arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "aws-global", null, null, true,
                "Invalid configuration: Multi-Region Access Point ARNs are disabled."
            ],
            [
                "arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "us-west-2", "dualstack", null, false,
                "S3 MRAP does not support dual-stack"
            ],
            [
                "arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap", "us-west-2", "accelerate", null, false,
                "S3 MRAP does not support S3 Accelerate"
            ],
            [
                "arn:aws:s3::123456789012:accesspoint:myendpoint", "us-west-2", null, null, true,
                "Invalid configuration: Multi-Region Access Point ARNs are disabled."
            ],
        ];
    }

    /**
     * @dataProvider mrapExceptionTestProvider
     */
    public function testMrapExceptions(
        $bucketFieldInput,
        $clientRegion,
        $additionalFlags,
        $useArnRegion,
        $disableMraps,
        $expectedException
    ) {
        $client = new S3Client([
            'region' => $clientRegion,
            'use_arn_region' => $useArnRegion,
            'version' => 'latest',
            'disable_multiregion_access_points' => $disableMraps,
            'use_dual_stack_endpoint' => !empty($additionalFlags) && $additionalFlags == 'dualstack',
            'use_accelerate_endpoint' => !empty($additionalFlags) && $additionalFlags == 'accelerate',
        ]);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => $bucketFieldInput,
                'Key' => 'Bar/Baz',
            ]
        );
        try {
            $client->execute($command);
            self::fail("exception should have been thrown");
        } catch (\Exception $e) {
            self::assertTrue(
                $e instanceof  UnresolvedEndpointException
                || $e instanceof S3Exception
            );
            self::assertStringContainsString($expectedException, $e->getMessage());
        }
    }

    /**
     * @dataProvider AccessPointFailureProvider
     * @param $bucketFieldInput
     * @param $clientRegion
     * @param $additionalFlags
     * @param $useArnRegion
     * @param $disableMraps
     * @param $expectedException
     */
    public function testAccessPointFailures (
        $bucketFieldInput,
        $clientRegion,
        $additionalFlags,
        $useArnRegion,
        $disableMraps,
        $expectedException
    ){
        $client = new S3Client([
            'region' => $clientRegion,
            'use_arn_region' => $useArnRegion,
            'version' => 'latest',
            'disable_multiregion_access_points' => $disableMraps,
            'use_dual_stack_endpoint' => !empty($additionalFlags) && $additionalFlags == 'dualstack',
            'use_accelerate_endpoint' => !empty($additionalFlags) && $additionalFlags == 'accelerate',
        ]);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => $bucketFieldInput,
                'Key' => 'Bar/Baz',
            ]
        );
        try {
            $client->execute($command);
            self::fail("exception should have been thrown");
        } catch (\Exception $e) {
            self::assertTrue(
                $e instanceof  UnresolvedEndpointException
                || $e instanceof S3Exception
            );
            self::assertStringContainsString($expectedException, $e->getMessage());
        }
    }
    public function AccessPointFailureProvider()
    {
        return [
            [
                "arn:aws:sqs:us-west-2:123456789012:someresource", "us-west-2", null, null, null,
                "Invalid ARN: Unrecognized format: arn:aws:sqs:us-west-2:123456789012:someresource (type: someresource)"
            ],
            [
                "arn:aws:s3:us-west-2:123456789012:bucket_name:mybucket", "us-west-2", null, null, null,
                "Invalid ARN: Unrecognized format: arn:aws:s3:us-west-2:123456789012:bucket_name:mybucket (type: bucket_name)"
            ],
            [
                "arn:aws:s3:us-west-2::accesspoint:myendpoint", "us-west-2", null, null, null,
                "Invalid ARN: The account id may only contain a-z, A-Z, 0-9 and `-`. Found: ``",
            ],
            [
                "arn:aws:s3:us-west-2:123.45678.9012:accesspoint:mybucket", "us-west-2", null, null, null,
                "Invalid ARN: The account id may only contain a-z, A-Z, 0-9 and `-`. Found: `123.45678.9012`"
            ],
            [
                "arn:aws:s3:us-west-2:123456789012:accesspoint", "us-west-2", null, null, null,
                "Invalid ARN: Expected a resource of the format `accesspoint:<accesspoint name>` but no name was provided"
            ],
            [
                "arn:aws:s3:us-west-2:123456789012:accesspoint:*", "us-west-2", null, null, null,
                "Invalid ARN: The access point name may only contain a-z, A-Z, 0-9 and `-`. Found: `*`"
            ],
            [
                "arn:aws:s3:us-west-2:123456789012:accesspoint:my.bucket", "us-west-2", null, null, null,
                "Invalid ARN: The access point name may only contain a-z, A-Z, 0-9 and `-`"
            ],
            [
                "arn:aws:s3:us-west-2:123456789012:accesspoint:mybucket:object:foo", "us-west-2", null, null, null,
                "Invalid ARN: The ARN may only contain a single resource component after `accesspoint`."
            ],
        ];
    }

    public function testPresignedMrapSuccess ()
    {
        if (!extension_loaded('awscrt')) {
            $this->markTestSkipped();
        }
        $arn = 'arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap';
        $expectedEndpoint = "mfzwi23gnjvgw.mrap.accesspoint.s3-global.amazonaws.com";
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'disable_multiregion_access_points' => false,
        ]);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => $arn,
                'Key' => 'Bar/Baz',
            ]
        );
        $presigned = $client->createPresignedRequest($command, time() + 10000);
        self::assertSame($expectedEndpoint, $presigned->getUri()->getHost());
        $url = (string) $presigned->getUri();
        $this->assertStringContainsString('Amz-Region-Set=%2A', $url);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-ECDSA-P256-SHA256', $url);
    }

    public function testPresignedMrapFailure ()
    {
        $arn = 'arn:aws:s3::123456789012:accesspoint:mfzwi23gnjvgw.mrap';
        $expectedException = "Invalid configuration: Multi-Region Access Point ARNs are disabled.";
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'disable_multiregion_access_points' => true,
        ]);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => $arn,
                'Key' => 'Bar/Baz',
            ]
        );
        try {
            $client->createPresignedRequest($command, time() + 10000);
            self::fail("exception should have been thrown");
        } catch (\Exception $e) {
            self::assertTrue($e instanceof  UnresolvedEndpointException);
            self::assertStringContainsString($expectedException, $e->getMessage());
        }
    }

    public function jsonCaseProvider()
    {
        return json_decode(
            file_get_contents(__DIR__ . '/test_cases/uri_addressing.json'),
            true
        );
    }

    /**
     * @dataProvider jsonCaseProvider
     *
     * @param array $testCase
     */
    public function testPassesCompliance(
        $bucket,
        $configuredAddressingStyle,
        $expectedUri,
        $region,
        $useDualstack,
        $useS3Accelerate
    ) {
        $key = 'key';
        $client = new S3Client([
            'region' => $region,
            'version' => 'latest',
            'validate' => false,
            'use_dual_stack_endpoint' => $useDualstack,
            'use_accelerate_endpoint' => $useS3Accelerate,
            'use_path_style_endpoint' => $configuredAddressingStyle === 'path',
            'handler' => function (
                CommandInterface $cmd,
                RequestInterface $req
            ) use ($key, $expectedUri) {
                $this->assertEquals($expectedUri . '/' . $key, trim($req->getUri(), '/'));
                return Promise\Create::promiseFor(new Result());
            },
        ]);

        $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ]);
    }

    /**
     * @dataProvider objectLambdasSuccessProvider
     *
     * @param $bucketFieldInput
     * @param $clientRegion
     * @param $additionalFlags
     * @param $useArnRegion
     * @param $endpointUrl
     * @param $expectedEndpoint
     */
    public function testObjectLambdaArnSuccess(
        $bucketFieldInput,
        $clientRegion,
        $additionalFlags,
        $useArnRegion,
        $endpointUrl,
        $expectedEndpoint)
    {
        //additional flags is not used yet, will be in the future if dualstack support is added
        $clientConfig = [
            'region' => $clientRegion,
            'use_arn_region' => $useArnRegion,
            'version' => 'latest',
            'handler' => function (CommandInterface $cmd, RequestInterface $req)
            use ($expectedEndpoint) {
                $this->assertSame(
                    $expectedEndpoint,
                    $req->getUri()->getHost()
                );
                $this->assertSame(
                    '/Bar/Baz',
                    $req->getUri()->getPath()
                );
                return new Result([]);
            },
        ];
        if (!empty($endpointUrl)) {
            $clientConfig['endpoint'] = $endpointUrl;
        }
        if (is_array($additionalFlags) && in_array('fips', $additionalFlags)) {
            $clientConfig['use_fips_endpoint'] = true;
        }
        $client = new S3Client($clientConfig);
        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => $bucketFieldInput,
                'Key' => 'Bar/Baz',
            ]
        );
        $client->execute($command);
    }

    public function objectLambdasSuccessProvider()
    {
        return [
            ["arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/mybanner", "us-east-1", "none", false, null, "mybanner-123456789012.s3-object-lambda.us-east-1.amazonaws.com"],
            ["arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint/mybanner", "us-west-2", "none", false, null, "mybanner-123456789012.s3-object-lambda.us-west-2.amazonaws.com"],
            ["arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint:mybanner", "us-west-2", "none", false, null, "mybanner-123456789012.s3-object-lambda.us-west-2.amazonaws.com"],
            ["arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/mybanner", "us-west-2", "none", true, null, "mybanner-123456789012.s3-object-lambda.us-east-1.amazonaws.com"],
            ["arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/mybanner", "s3-external-1", "none", true, null, "mybanner-123456789012.s3-object-lambda.us-east-1.amazonaws.com"],
            ["arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/mybanner", "aws-global", "none", true, null, "mybanner-123456789012.s3-object-lambda.us-east-1.amazonaws.com"],
            ["arn:aws-cn:s3-object-lambda:cn-north-1:123456789012:accesspoint/mybanner", "cn-north-1", "none", true, null, "mybanner-123456789012.s3-object-lambda.cn-north-1.amazonaws.com.cn"],
            ["arn:aws-cn:s3-object-lambda:cn-north-1:123456789012:accesspoint/mybanner", "cn-north-1", "none", false, null, "mybanner-123456789012.s3-object-lambda.cn-north-1.amazonaws.com.cn"],
            ["arn:aws-cn:s3-object-lambda:cn-northwest-1:123456789012:accesspoint/mybanner", "cn-north-1", "none", true, null, "mybanner-123456789012.s3-object-lambda.cn-northwest-1.amazonaws.com.cn"],
            ["arn:aws-us-gov:s3-object-lambda:us-gov-east-1:123456789012:accesspoint/mybanner", "us-gov-east-1", "none", true, null, "mybanner-123456789012.s3-object-lambda.us-gov-east-1.amazonaws.com"],
            ["arn:aws-us-gov:s3-object-lambda:us-gov-east-1:123456789012:accesspoint/mybanner", "fips-us-gov-east-1", "none", true, null, "mybanner-123456789012.s3-object-lambda-fips.us-gov-east-1.amazonaws.com"],
            ["arn:aws-us-gov:s3-object-lambda:us-gov-east-1:123456789012:accesspoint/mybanner", "fips-us-gov-east-1", "none", false, null, "mybanner-123456789012.s3-object-lambda-fips.us-gov-east-1.amazonaws.com"],
            ["arn:aws-us-gov:s3-object-lambda:us-gov-east-1:123456789012:accesspoint/mybanner", "us-gov-east-1", ["fips"], false, null, "mybanner-123456789012.s3-object-lambda-fips.us-gov-east-1.amazonaws.com"],
            ["arn:aws-us-gov:s3-object-lambda:us-gov-west-1:123456789012:accesspoint/mybanner", "fips-us-gov-east-1", "none", true, null, "mybanner-123456789012.s3-object-lambda-fips.us-gov-west-1.amazonaws.com"],
            ["arn:aws-us-gov:s3-object-lambda:us-gov-west-1:123456789012:accesspoint/mybanner", "us-gov-east-1", ["fips"], true, null, "mybanner-123456789012.s3-object-lambda-fips.us-gov-west-1.amazonaws.com"],
        ];
    }

    /**
     * @dataProvider objectLambdasFailureProvider
     *
     * @param $bucketFieldInput
     * @param $clientRegion
     * @param $additionalFlags
     * @param $useArnRegion
     * @param $endpointUrl
     * @param $expectedException
     */
    public function testObjectLambdaArnFailures(
        $bucketFieldInput,
        $clientRegion,
        $additionalFlags,
        $useArnRegion,
        $endpointUrl,
        $expectedException)
    {
        $clientConfig = [
            'region' => $clientRegion,
            'use_arn_region' => $useArnRegion,
            'version' => 'latest',
            'handler' => function (CommandInterface $cmd, RequestInterface $req)
            use ($expectedException) {
                $this->assertSame(
                    $expectedException,
                    $req->getUri()->getHost()
                );
                $this->assertSame(
                    '/Bar/Baz',
                    $req->getUri()->getPath()
                );
                return new Result([]);
            },
        ];
        if (!empty($additionalFlags) && $additionalFlags == 'dualstack') {
            $clientConfig['use_dual_stack_endpoint'] = true;
        }
        if (!empty($additionalFlags) && $additionalFlags == 'accelerate') {
            $clientConfig['use_accelerate_endpoint'] = true;
        }
        $client = new S3Client($clientConfig);

        $command = $client->getCommand(
            'GetObject',
            [
                'Bucket' => $bucketFieldInput,
                'Key' => 'Bar/Baz',
            ]
        );
        try {
            $client->execute($command);
            $this->fail("did not catch exception: " . $expectedException);
        } catch (\Exception $e) {
            $this->assertStringContainsString($expectedException, $e->getMessage());
        }
    }

    public function objectLambdasFailureProvider()
    {
        return [
            [
                "arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/mybanner", "us-west-2", "none", false, null,
                'Invalid configuration: region from ARN `us-east-1` does not match client region `us-west-2` and UseArnRegion is `false`'            ]
            ,
            [
                "arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint/mybanner", "us-west-2", "dualstack", true, null,
                'S3 Object Lambda does not support Dual-stack'
            ],
            [
                "arn:aws-cn:s3-object-lambda:cn-north-1:123456789012:accesspoint/mybanner", "us-west-2", "none", true, null,
                'Client was configured for partition `aws` but ARN (`arn:aws-cn:s3-object-lambda:cn-north-1:123456789012:accesspoint/mybanner`) has `aws-cn`'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint/mybanner", "us-west-2", "accelerate", null, null,
                'S3 Object Lambda does not support S3 Accelerate'
            ],
            [
                "arn:aws:sqs:us-west-2:123456789012:someresource", "us-west-2", "n/a", null, null,
                'Invalid ARN: Unrecognized format: arn:aws:sqs:us-west-2:123456789012:someresource (type: someresource)'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2:123456789012:bucket_name:mybucket", "us-west-2", "n/a", null, null,
                'Invalid ARN: Object Lambda ARNs only support `accesspoint` arn types, but found: `bucket_name`'
            ],
            [
                "arn:aws:s3-object-lambda::123456789012:accesspoint/mybanner", "us-west-2", "none", null, null,
                'Invalid ARN: bucket ARN is missing a region'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2::accesspoint/mybanner", "us-west-2", "none", null, null,
                'Invalid ARN: Missing account id'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2:123.45678.9012:accesspoint:mybucket", "us-west-2", "n/a", null, null,
                'Invalid ARN: The account id may only contain a-z, A-Z, 0-9 and `-`. Found: `123.45678.9012`'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint", "us-west-2", "n/a", null, null,
                'Invalid ARN: Expected a resource of the format `accesspoint:<accesspoint name>` but no name was provided'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint:*", "us-west-2", "n/a", null, null,
                'Invalid ARN: The access point name may only contain a-z, A-Z, 0-9 and `-`. Found: `*`'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint:my.bucket", "us-west-2", "n/a", null, null,
                'Invalid ARN: The access point name may only contain a-z, A-Z, 0-9 and `-`. Found: `my.bucket`'
            ],
            [
                "arn:aws:s3-object-lambda:us-west-2:123456789012:accesspoint:mybucket:object:foo", "us-west-2", "n/a", null, null,
                'Invalid ARN: The ARN may only contain a single resource component after `accesspoint`.'
            ],
            [
                "arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/mybanner", "s3-external-1", "none", false, null,
                'Invalid configuration: region from ARN `us-east-1` does not match client region `s3-external-1` and UseArnRegion is `false`'
            ],
            [
                "arn:aws:s3-object-lambda:us-east-1:123456789012:accesspoint/mybanner", "aws-global", "none", false, null,
                'Invalid configuration: region from ARN `us-east-1` does not match client region `aws-global` and UseArnRegion is `false`'
            ],
            [
                "arn:aws-us-gov:s3-object-lambda:us-gov-west-1:123456789012:accesspoint/mybanner", "fips-us-gov-east-1", "none", false, null,
                'Invalid configuration: region from ARN `us-gov-west-1` does not match client region `us-gov-east-1` and UseArnRegion is `false`'
            ],
        ];
    }


    /**
     * @dataProvider writeGetObjectResponseProvider
     *
     * @param $clientRegion
     * @param $route
     * @param $endpointUrl
     * @param $expectedEndpoint
     */
    public function testWriteGetObjectResponse(
        $clientRegion,
        $route,
        $endpointUrl,
        $expectedEndpoint
    )
    {
        $clientConfig = [
            'region' => $clientRegion,
            'version' => 'latest',
            'handler' => function (CommandInterface $cmd, RequestInterface $req)
            use ($expectedEndpoint) {
                $this->assertSame(
                    $expectedEndpoint,
                    $req->getUri()->getHost()
                );
                return new Result([]);
            },
        ];
        if (!empty($endpointUrl)) {
            $clientConfig['endpoint'] = $endpointUrl;
        }
        $client = new S3Client($clientConfig);
        $command = $client->getCommand(
            'WriteGetObjectResponse',
            [
                'RequestRoute' => $route,
                'RequestToken' => 'def'
            ]
        );
        $client->execute($command);
    }

    public function writeGetObjectResponseProvider()
    {
        return [
            ["us-west-2", "route", null, 'route.s3-object-lambda.us-west-2.amazonaws.com'],
            ["us-east-1", "route", null, 'route.s3-object-lambda.us-east-1.amazonaws.com'],
        ];
    }

    public function testLeadingForwardSlashIsEncoded()
    {
        $s3 = $this->getTestClient('s3');
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand('listObjects', ['Bucket' => 'foo', 'Prefix' => '/']);
        $command->getHandlerList()->appendSign(
            Middleware::tap(function ($cmd, $req) {
                $this->assertSame('foo.s3.amazonaws.com', $req->getUri()->getHost());
                $this->assertSame('/?prefix=%2F&encoding-type=url', $req->getRequestTarget());
            })
        );
        $s3->execute($command);
    }

    public function testHandlesTrailingForwardSlashInCustomEndpoint()
    {
        $s3 = $this->getTestClient('s3', ['endpoint' => 'https://test.com/']);
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand('listObjects', ['Bucket' => 'foo', 'Prefix' => '/']);
        $command->getHandlerList()->appendSign(
            Middleware::tap(function ($cmd, $req) {
                $this->assertSame('foo.test.com', $req->getUri()->getHost());
                $this->assertSame('/?prefix=%2F&encoding-type=url', $req->getRequestTarget());
            })
        );
        $s3->execute($command);
    }

    public function testAddsForwardSlashIfEmptyPathAndQuery()
    {
        $s3 = $this->getTestClient('s3');
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand('listObjectsV2', ['Bucket' => 'foo']);
        $command->getHandlerList()->appendSign(
            Middleware::tap(function ($cmd, $req) {
                $this->assertSame('/', $req->getUri()->getPath());
                $this->assertSame('list-type=2', $req->getUri()->getQuery());
            })
        );
        $s3->execute($command);
    }

    public function addMD5Provider() {
        return [
           [
               ['Bucket' => 'foo', 'Key' => 'foo', 'Body' => 'test'],
               'PutObject'
           ],
           [
               [
                   'Bucket' => 'foo',
                   'Key' => 'foo',
                   'Body' => 'test',
                   'PartNumber' => 1,
                   'UploadId' => 'foo',
               ],
               'UploadPart'
           ]
        ];
    }

    /**
     * @dataProvider addMD5Provider
     */
    public function testAddContentMd5EmitsDeprecationNotice($options, $operation)
    {
        $this->expectDeprecation();
        $this->expectExceptionMessage('S3 no longer supports MD5 checksums.');
        $s3 = $this->getTestClient('s3');
        $this->addMockResults($s3, [[]]);
        $options['AddContentMD5'] = true;
        $command = $s3->getCommand($operation, $options);
        $s3->execute($command);
    }

    /**
     * @dataProvider dotSegmentProvider
     */
    public function testHandlesDotSegmentsInKey($key, $expectedUri)
    {
        $s3 = $this->getTestClient('s3');
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand('getObject', ['Bucket' => 'foo', 'Key' => $key]);
        $command->getHandlerList()->appendSign(
            Middleware::tap(function ($cmd, $req) use ($expectedUri) {
                $this->assertSame($expectedUri, (string) $req->getUri());
            })
        );
        $s3->execute($command);
    }

    public function dotSegmentProvider()
    {
        return [
            ['../foo' , 'https://foo.s3.amazonaws.com/../foo'],
            ['bar/../../foo', 'https://foo.s3.amazonaws.com/bar/../../foo'],
            ['/../foo', 'https://foo.s3.amazonaws.com//../foo'],
            ['foo/bar/../baz', 'https://foo.s3.amazonaws.com/foo/bar/../baz'],
            ['foo/bar/baz/..', 'https://foo.s3.amazonaws.com/foo/bar/baz/..']
        ];
    }

    /**
     * @dataProvider dotSegmentPathStyleProvider
     */
    public function testHandlesDotSegmentsInKeyWithPathStyle($key, $expectedUri)
    {
        $s3 = $this->getTestClient('s3', ['use_path_style_endpoint' => true]);
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand('getObject', ['Bucket' => 'bucket', 'Key' => $key]);
        $command->getHandlerList()->appendSign(
            Middleware::tap(function ($cmd, $req) use ($expectedUri) {
                $this->assertSame($expectedUri, (string) $req->getUri());
            })
        );
        $s3->execute($command);
    }

    public function dotSegmentPathStyleProvider()
    {
        return [
            ['../foo' , 'https://s3.amazonaws.com/bucket/../foo'],
            ['bar/../../foo', 'https://s3.amazonaws.com/bucket/bar/../../foo'],
            ['/../foo', 'https://s3.amazonaws.com/bucket//../foo'],
            ['foo/bar/../baz', 'https://s3.amazonaws.com/bucket/foo/bar/../baz'],
            ['foo/bar/baz/..', 'https://s3.amazonaws.com/bucket/foo/bar/baz/..']
        ];
    }

    /**
     * @dataProvider builtinRegionProvider
     */
    public function testCorrectlyResolvesGlobalEndpointWithoutRegionInConstructor(
        $region, $expected
    ){
        putenv('AWS_REGION=' . $region);

        $s3Client = new S3Client([]);
        $builtIns = $s3Client->getClientBuiltIns();
        //The UseGlobalEndpoint builtin should be set by default if
        //the region provided is us-east-1.
        $this->assertEquals($expected, isset($builtIns['AWS::S3::UseGlobalEndpoint']));

        //When the UseGlobalEndpoint builtin is set (i.e. us-east-1 is the region)
        // the default value should be `true`, unless `s3_us_east_1_regional_endpoint`
        // is set to `regional`.
        if ($expected) {
            $this->assertEquals($expected, $builtIns['AWS::S3::UseGlobalEndpoint']);
        }

        putenv('AWS_REGION=');
    }

    public function testExpiresStringInResult()
    {
        $client = new S3Client([
            'region' => 'us-east-1',
            'http_handler' => function (RequestInterface $request) {
                return Promise\Create::promiseFor(new Response(
                    200,
                    ['expires' => '1989-08-05']
                ));
            },
        ]);
        $result = $client->headObject(['Bucket' => 'foo', 'Key' => 'bar']);
        $this->assertInstanceOf(DateTimeResult::class, $result['Expires']);
        $this->assertEquals('1989-08-05', $result['ExpiresString']);
    }

    public function testEmitsWarningWhenExpiresUnparseable()
    {
        $this->expectWarning();
        $this->expectWarningMessage(
            "Failed to parse the `expires` header as a timestamp due to "
            . " an invalid timestamp format.\nPlease refer to `ExpiresString` "
            . "for the unparsed string format of this header.\n"
        );

        $client = new S3Client([
            'region' => 'us-east-1',
            'http_handler' => function (RequestInterface $request) {
                return Promise\Create::promiseFor(new Response(
                    200,
                    ['expires' => 'this-is-not-a-timestamp']
                ));
            },
        ]);

        $client->headObject(['Bucket' => 'foo', 'Key' => 'bar']);
    }

    public function testExpiresRemainsTimestamp() {
        //S3 will be changing `Expires` type from `timestamp` to `string`
        // soon.  This test ensures backward compatibility
        $apiProvider = static function () {
            return [
                'metadata' => [
                    'signatureVersion' => 'v4',
                    'protocol' => 'rest-xml'
                ],
                'shapes' => [
                    'Expires' => [
                        'type' => 'string'
                    ],
                ],
            ];
        };

        $s3Client = new S3Client([
            'region' => 'us-west-2',
            'api_provider' => $apiProvider
        ]);

        $api = $s3Client->getApi();
        $expiresType = $api->getDefinition()['shapes']['Expires']['type'];
        $this->assertEquals('timestamp', $expiresType);
    }

    public function testBucketNotModifiedWithLegacyEndpointProvider()
    {
        $client = new S3Client([
            'region' => 'us-west-2',
            'endpoint_provider' => PartitionEndpointProvider::defaultProvider()
        ]);

        $operations = $client->getApi()->getDefinition()['operations'];
        $this->assertEquals('/{Bucket}', $operations['ListObjects']['http']['requestUri']);
        $this->assertEquals(
            '/{Bucket}?versions',
            $operations['ListObjectVersions']['http']['requestUri']
        );
    }

    public function builtinRegionProvider()
    {
        return [
            ['us-east-1' , true],
            ['us-west-2', false]
        ];
    }

    /**
     * This test makes sure that not parsable xml errors are retried.
     * This handling is specified in the s3 parser implementation.
     *
     * @dataProvider clientRetrySettingsProvider
     * @param array $retrySettings
     *
     * @return void
     */
    public function testS3RetriesOnNotParsableBody(array $retrySettings)
    {
        $retries = $retrySettings['max_attempts'];
        $client = new S3Client([
            'region' => 'us-east-2',
            'version' => 'latest',
            'retries' => $retrySettings,
            'http_handler' => function (RequestInterface $req) use (&$retries) {
                if (0 === --$retries) {
                    return new Response(200, [], $this->getWellFormedXml());
                }

                return new Response(200, [], $this->getMalformedXml());
            }
        ]);
        $client->listBuckets();
        $this->assertEquals(0, $retries);
    }
  
  /**
     * @param string $bucketName
     * @param bool $expected
     *
     * @return void
     *
     * @dataProvider directoryBucketProvider
     */
    public function testIsDirectoryBucket(string $bucketName, bool $expected): void
    {
        $client = $this->getTestClient('s3');
        $this->assertEquals($expected, $client::isDirectoryBucket($bucketName));
    }

    public function directoryBucketProvider(): array
    {
        return [
            ['bucket-base-name--usw2-az1--x-s3', true],
            ['mybucket123--euw1-az2--x-s3', true],
            ['test_bucket_name--apne1-az3--x-s3', true],
            ['valid-name--aps1-az4--x-s3', true],
            ['s3_express_demo_directory_bucket--usw2-az1--x-s3', true],
            ['bucket-name--usw2-az1--s3alias', false], // ends with -s3alias
            ['bucketname--usw2-az1--ol-s3', false],    // ends with --ol-s3
            ['bucketname--usw2-az1.mrap', false],      // ends with .mrap
            ['invalid_bucket_name--az1--x-s3', false], // missing region in azid
            ['name--usw2-az1', false],                 // missing --x-s3 at the end
            ['wrong-format--usw2az1--x-s3', false],    // missing hyphen in azid part
            ['too-short--x-s3', false],                // invalid azid format, missing prefix
        ];
    }

    public function testAddCrc32ForDirectoryBucketsAsAppropriate()
    {
        $s3 = $this->getTestClient('s3');
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand('putBucketPolicy', [
            'Bucket' => 'mybucket--use1-az1--x-s3',
            'Policy' => 'policy'
        ]);
        $command->getHandlerList()->appendBuild(
            Middleware::tap(function ($cmd, RequestInterface $request) {
                $this->assertFalse($request->hasHeader('Content-MD5'));
                $this->assertSame('8H0FFg==', $request->getHeaderLine('x-amz-checksum-crc32'));
            })
        );
        $s3->execute($command);
    }

    /**
     * @dataProvider getContentSha256UseCases
     */
    public function testAddsContentSHA256AsAppropriate($operation, $args, $hashAdded, $hashValue)
    {
        $s3 = $this->getTestClient('s3');
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand($operation, $args);
        $command->getHandlerList()->appendBuild(
            Middleware::tap(function ($cmd, RequestInterface $request) use ($hashAdded, $hashValue) {
                $this->assertSame($hashAdded, $request->hasHeader('x-amz-content-sha256'));
                $this->assertEquals($hashValue, $request->getHeaderLine('x-amz-content-sha256'));
            })
        );
        $s3->execute($command);
    }

    public function getContentSha256UseCases()
    {
        $hash = 'SHA256HASH';

        return [
            // Do nothing if ContentSHA256 was not provided.
            [
                'PutObject',
                ['Bucket' => 'foo', 'Key' => 'bar', 'Body' => 'baz'],
                false,
                ''
            ],
            // Gets added for operations that allow it.
            [
                'PutObject',
                ['Bucket' => 'foo', 'Key' => 'bar', 'Body' => 'baz', 'ContentSHA256' => $hash],
                true,
                $hash
            ],
            // Not added for operations that do not allow it.
            [
                'GetObject',
                ['Bucket' => 'foo', 'Key' => 'bar', 'ContentSHA256' => $hash],
                false,
                '',
            ],
        ];
    }

    /**
     * @dataProvider getFlexibleChecksumUseCases
     */
    public function testAddsFlexibleChecksumAsAppropriate($operation, $clientArgs, $operationArgs, $headerAdded, $headerValue)
    {
        if (isset($operationArgs['ChecksumAlgorithm'])
            && $operationArgs['ChecksumAlgorithm'] === 'crc32c'
            && !extension_loaded('awscrt')
        ) {
            $this->markTestSkipped("Cannot test crc32c without the CRT");
        }
        $s3 = $this->getTestClient('s3', $clientArgs);
        $this->addMockResults($s3, [[]]);
        $command = $s3->getCommand($operation, $operationArgs);
        $command->getHandlerList()->appendBuild(
            Middleware::tap(function ($cmd, RequestInterface $request) use ($headerAdded, $headerValue, $operationArgs) {
                $checksumName = $operationArgs['ChecksumAlgorithm'] ?? "crc32";
                if ($headerAdded) {
                    $this->assertTrue($request->hasHeader("x-amz-checksum-{$checksumName}"));
                }
                $this->assertEquals($headerValue, $request->getHeaderLine("x-amz-checksum-{$checksumName}"));
            })
        );
        $s3->execute($command);
    }

    public function getFlexibleChecksumUseCases()
    {
        return [
            // httpChecksum not modeled
            [
                'GetObject',
                [],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'ChecksumMode' => 'ENABLED'
                ],
                false,
                ''
            ],
            // default: when_supported. defaults to crc32
            [
                'PutObject',
                [],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'Body' => 'abc'
                ],
                true,
                'NSRBwg=='
            ],
            // when_required when not required and no requested algorithm
            [
                'PutObject',
                ['request_checksum_calculation' => 'when_required'],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'Body' => 'abc'
                ],
                false,
                ''
            ],
            // when_required when required and no requested algorithm
            [
                'PutObjectLockConfiguration',
                ['request_checksum_calculation' => 'when_required'],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'ObjectLockConfiguration' => []
                ],
                true,
                'UHB63w=='
            ],
            // when_required when not required and requested algorithm
            [
                'PutObject',
                ['request_checksum_calculation' => 'when_required'],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'Body' => 'blah',
                    'ChecksumAlgorithm' => 'crc32',
                ],
                true,
                'zilhXA=='
            ],
            // when_supported and requested algorithm
            [
                'PutObject',
                [],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'ChecksumAlgorithm' => 'crc32c',
                    'Body' => 'abc'
                ],
                true,
                'Nks/tw=='
            ],
            // when_supported and requested algorithm
            [
                'PutObject',
                [],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'ChecksumAlgorithm' => 'sha256'
                ],
                true,
                '47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU='
            ],
            // when_supported and requested algorithm
            [
                'PutObject',
                [],
                [
                    'Bucket' => 'foo',
                    'Key' => 'bar',
                    'ChecksumAlgorithm' => 'SHA1'
                ],
                true,
                '2jmj7l5rSw0yVb/vlWAYkK/YBwk='
            ]
        ];
    }

    /**
     * @param array $clientConfig
     * @return void
     *
     * @dataProvider responseChecksumValidationProvider
     */
    public function testResponseChecksumValidation(
        array $clientConfig,
        ?string $checksumAlgorithm,
        ?string $mode
    ): void
    {
        if ($checksumAlgorithm === 'CRC32C'
            && !extension_loaded('awscrt')
        ) {
            $this->markTestSkipped("Cannot test crc32c without the awscrt");
        }

        $handler = static function (RequestInterface $request) use ($checksumAlgorithm) {
            return Promise\Create::promiseFor(new Response(
                200,
                ['x-amz-checksum-' . $checksumAlgorithm => 'AAAAAA==']
            ));
        };
        $client = $this->getTestClient('s3', $clientConfig + ['http_handler' => $handler]);


        $result = $client->getObject([
            'Bucket' => 'bucket',
            'Key' => 'key',
            'ChecksumMode' => $mode
        ]);

        $this->assertEquals($checksumAlgorithm, $result['ChecksumValidated']);
    }

    public function responseChecksumValidationProvider(): array
    {
        return [
            [
                //default, when_supported, validates checksum for operation with modeled response checksums
                [],
                'CRC32',
                null
            ],
            [
                //default, when_supported, validates checksum for operation with modeled response checksums when
                // CRT installed
                [],
                'CRC32C',
                null
            ],
            [
                // when_required, validates checksum for operation with modeled response checksums
                // and mode is "enabled"
                ['response_checksum_validation' => 'when_required'],
                'CRC32',
                'enabled'
            ],
            [
                // when_required, validates checksum validation for operation with modeled response checksums
                // and mode is "enabled" when CRT installed
                ['response_checksum_validation' => 'when_required'],
                'CRC32C',
                'enabled'
            ],
            [
                // when_required, skips checksum validation for operation with modeled response checksums
                ['response_checksum_validation' => 'when_required'],
                null,
                null
            ],
        ];
    }

    /**
     * @dataProvider checksumConfigProvider
     * @return void
     */
    public function testChecksumConfigThrowsForInvalidInput(
        string $option,
        string $invalidOption
    ): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'valid values are: when_supported | when_required.'
        );
        $this->getTestClient(
            's3',
            [$option => 'foo']
        );
    }

    public function checksumConfigProvider()
    {
        return [
            ['request_checksum_calculation', 'foo'],
            ['response_checksum_validation', 'foo']
        ];
    }

    public function testCreatesPresignedRequestsWithoutChecksumByDefault()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region' => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand('PutObject', ['Bucket' => 'foo', 'Key' => 'bar']);
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringNotContainsString('x-amz-checksum-', $url);
        $this->assertStringNotContainsString('x-amz-sdk-checksum-', $url);
    }

    public function testCreatesPresignedRequestsWithRequestedChecksumAlgorithm()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region' => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand(
            'PutObject',
            ['Bucket' => 'foo', 'Key' => 'bar', 'ChecksumAlgorithm' => 'crc32']
        );
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringContainsString('x-amz-checksum-crc32=AAAAAA%3D%3D', $url);
        $this->assertStringContainsString('x-amz-sdk-checksum-algorithm=crc32', $url);
    }

    public function testCreatesPresignedRequestsWithContentSha256Value()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region' => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand(
            'PutObject',
            ['Bucket' => 'foo', 'Key' => 'bar', 'ContentSHA256' => 'foo']
        );
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringContainsString('X-Amz-Content-Sha256=foo', $url);
        $this->assertStringNotContainsString('x-amz-sdk-checksum-algorithm', $url);
        $this->assertStringNotContainsString('x-amz-checksum', $url);
    }

    public function testCreatesPresignedPutRequestsWithChecksumValue()
    {
        /** @var S3Client $client */
        $client = $this->getTestClient('S3', [
            'region' => 'us-east-1',
            'credentials' => ['key' => 'foo', 'secret' => 'bar']
        ]);
        $command = $client->getCommand(
            'PutObject',
            ['Bucket' => 'foo', 'Key' => 'bar','ChecksumCRC32' => 'AAAAAA==']
        );
        $url = (string) $client->createPresignedRequest($command, 1342138769)->getUri();
        $this->assertStringContainsString('x-amz-checksum-crc32=AAAAAA%3D%3D', $url);
        $this->assertStringNotContainsString('x-amz-sdk-checksum-algorithm=crc32', $url);
    }

    /**
     * The purpose of this test is to ensure ApplyChecksumMiddleware is
     * not invoked twice, ensuring checksum calculation is not repeated.
     *
     * @dataProvider retriesWithoutRecalculatingChecksumProvider
     */
    public function testRetriesWithoutRecalculatingChecksum(
        string $commandName,
        array $commandArgs,
        string $retryMode
    ): void
    {
        $checksumHeader = 'x-amz-checksum-crc32';
        $checksumValue = 'V/RnXQ==';
        $command = new Command($commandName, $commandArgs);
        $mockHandler = new MockHandler([
            new AwsException(
                'Simulated retryable error',
                $command,
                [
                    'code' => 'Throttling',
                    'response' => new Response(500)
                ]
            ),
            new Result([
                'ETag' => '"abc123"',
                '@metadata' => [
                    'headers' => [
                        $checksumHeader => $checksumValue
                    ]
                ]
            ])
        ]);

        $client = $this->getTestClient('s3', [
            'handler' => $mockHandler,
            'retries' => [
                'mode' => $retryMode,
                'max_attempts' => 2
            ],
            'credentials' => [
                'key' => 'foo',
                'secret' => 'bar'
            ]
        ]);

        $checksumMiddlewareCalls = 0;

        // Add counter middleware ensuring handlerList is not resolved again
        // i.e. ApplyChecksumMiddleware was not called again
        $client->getHandlerList()->appendBuild(
            Middleware::tap(function ($cmd, $req) use (
                &$checksumMiddlewareCalls,
                $checksumHeader,
                $checksumValue
            ) {
                $this->assertTrue($req->hasHeader($checksumHeader));
                $this->assertEquals($checksumValue, $req->getHeaderLine($checksumHeader));
                $checksumMiddlewareCalls++;
            })
        );

        $client->$commandName($commandArgs);

        $this->assertEquals(
            1,
            $checksumMiddlewareCalls,
            'middleware should only run once, not on retry'
        );
    }

    public function retriesWithoutRecalculatingChecksumProvider(): array
    {
        return [
            'PutObject legacy' => [
                'PutObject',
                [
                    'Bucket' => 'my-bucket',
                    'Key' => 'example.txt',
                    'Body' => 'test content',
                    'ChecksumAlgorithm' => 'CRC32'
                ],
                'legacy'
            ],
            'PutObject standard' => [
                'PutObject',
                [
                    'Bucket' => 'my-bucket',
                    'Key' => 'example.txt',
                    'Body' => 'test content',
                    'ChecksumAlgorithm' => 'CRC32'
                ],
                'standard'
            ],
            'PutObject adaptive' => [
                'PutObject',
                [
                    'Bucket' => 'my-bucket',
                    'Key' => 'example.txt',
                    'Body' => 'test content',
                    'ChecksumAlgorithm' => 'CRC32'
                ],
                'adaptive'
            ],
            'UploadPart legacy' => [
                'UploadPart',
                [
                    'Bucket' => 'my-bucket',
                    'Key' => 'example.txt',
                    'Body' => 'test content',
                    'PartNumber' => 1,
                    'UploadId' => 'test-upload-id',
                    'ChecksumAlgorithm' => 'CRC32'
                ],
                'legacy'
            ],
            'UploadPart standard' => [
                'UploadPart',
                [
                    'Bucket' => 'my-bucket',
                    'Key' => 'example.txt',
                    'Body' => 'test content',
                    'PartNumber' => 1,
                    'UploadId' => 'test-upload-id',
                    'ChecksumAlgorithm' => 'CRC32'
                ],
                'standard'
            ],
            'UploadPart adaptive' => [
                'UploadPart',
                [
                    'Bucket' => 'my-bucket',
                    'Key' => 'example.txt',
                    'Body' => 'test content',
                    'PartNumber' => 1,
                    'UploadId' => 'test-upload-id',
                    'ChecksumAlgorithm' => 'CRC32'
                ],
                'adaptive'
            ]
        ];
    }
}
