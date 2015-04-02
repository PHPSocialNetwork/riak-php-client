<?php

/*
Copyright 2015 Basho Technologies, Inc.

Licensed to the Apache Software Foundation (ASF) under one or more contributor license agreements.  See the NOTICE file
distributed with this work for additional information regarding copyright ownership.  The ASF licenses this file
to you under the Apache License, Version 2.0 (the "License"); you may not use this file except in compliance
with the License.  You may obtain a copy of the License at

  http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software distributed under the License is distributed on an
"AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.  See the License for the
specific language governing permissions and limitations under the License.
*/

namespace Basho\Tests;

use Basho\Riak\Command;

/**
 * Class ObjectTest
 *
 * Functional tests related to Key-Value objects
 *
 * @author Christopher Mancini <cmancini at basho d0t com>
 */
class ObjectTest extends TestCase
{
    private static $key = '';

    /**
     * @var \Basho\Riak\Object|null
     */
    private static $object = NULL;

    /**
     * @var array|null
     */
    private static $vclock = NULL;

    public static function setUpBeforeClass()
    {
        // make completely random key based on time
        static::$key = md5(rand(0, 99) . time());
    }

    /**
     * @dataProvider getLocalNodeConnection
     * @param $riak \Basho\Riak
     */
    public function testStoreNewWithoutKey($riak)
    {
        // build an object
        $command = (new Command\Builder\StoreObject($riak))
            ->buildObject('some_data')
            ->buildBucket('users')
            ->build();

        $response = $command->execute($command);

        // expects 201 - Created
        $this->assertEquals('201', $response->getStatusCode());
        $this->assertNotEmpty($response->getLocation());
        $this->assertInstanceOf('\Basho\Riak\Location', $response->getLocation());
    }

    /**
     * @dataProvider getLocalNodeConnection
     * @param $riak \Basho\Riak
     */
    public function testFetchNotFound($riak)
    {
        $command = (new Command\Builder\FetchObject($riak))
            ->buildLocation(static::$key, 'users')
            ->build();

        $response = $command->execute($command);

        $this->assertEquals('404', $response->getStatusCode());
    }

    /**
     * @depends      testFetchNotFound
     * @dataProvider getLocalNodeConnection
     *
     * @param $riak \Basho\Riak
     *
     * @expectedException \Basho\Riak\Command\Exception
     */
    public function testStoreNewWithKey($riak)
    {
        $command = (new Command\Builder\StoreObject($riak))
            ->buildObject('some_data')
            ->buildLocation(static::$key, 'users')
            ->build();

        $response = $command->execute($command);

        // expects 204 - No Content
        // this is wonky, its not 201 because the key may have been generated on another node
        $this->assertEquals('204', $response->getStatusCode());
        $this->assertEmpty($response->getLocation());
    }

    /**
     * @depends      testStoreNewWithKey
     * @dataProvider getLocalNodeConnection
     * @param $riak \Basho\Riak
     */
    public function testFetchOk($riak)
    {
        $command = (new Command\Builder\FetchObject($riak))
            ->buildLocation(static::$key, 'users')
            ->build();

        $response = $command->execute($command);

        $this->assertEquals('200', $response->getStatusCode());
        $this->assertInstanceOf('Basho\Riak\Object', $response->getObject());
        $this->assertEquals('some_data', $response->getObject()->getData());
        $this->assertNotEmpty($response->getVClock());

        static::$object = $response->getObject();
    }

    /**
     * @depends      testFetchOk
     * @dataProvider getLocalNodeConnection
     *
     * @param $riak \Basho\Riak
     */
    public function testStoreExisting($riak)
    {
        $object = static::$object;

        $object->setData('some_new_data');

        $command = (new Command\Builder\StoreObject($riak))
            ->withObject($object)
            ->buildLocation(static::$key, 'users')
            ->build();

        $response = $command->execute($command);

        // 204 - No Content
        $this->assertEquals('204', $response->getStatusCode());
    }

    /**
     * @depends      testStoreExisting
     * @dataProvider getLocalNodeConnection
     *
     * @param $riak \Basho\Riak
     */
    public function testDelete($riak)
    {
        $command = (new Command\Builder\DeleteObject($riak))
            ->buildLocation(static::$key, 'users')
            ->build();

        $response = $command->execute($command);

        $this->assertEquals('204', $response->getStatusCode());
    }

    /**
     * @depends      testDelete
     * @dataProvider getLocalNodeConnection
     *
     * @param $riak \Basho\Riak
     */
    public function testFetchDeleted($riak)
    {
        $command = (new Command\Builder\FetchObject($riak))
            ->buildLocation(static::$key, 'users')
            ->build();

        $response = $command->execute($command);

        $this->assertEquals('404', $response->getStatusCode());
    }


    /**
     * @dataProvider getLocalNodeConnection
     *
     * @param $riak \Basho\Riak
     */
    public function testStoreObjectWithIndexes($riak)
    {
        $object = static::$object;

        $object->setData('person');
        $object->addValueToIndex('lucky_numbers_int', 42);
        $object->addValueToIndex('lucky_numbers_int', 64);
        $object->addValueToIndex('lastname_bin', 'Knuth');

        $command = (new Command\Builder\StoreObject($riak))
            ->withObject($object)
            ->buildLocation(static::$key, 'users', static::LEVELDB_BUCKET_TYPE)
            ->build();

        $response = $command->execute($command);

        $this->assertEquals('204', $response->getStatusCode());
    }

    /**
     * @depends      testStoreObjectWithIndexes
     * @dataProvider getLocalNodeConnection
     *
     * @param $riak \Basho\Riak
     */
    public function testFetchObjectWithIndexes($riak)
    {
        $command = (new Command\Builder\FetchObject($riak))
            ->buildLocation(static::$key, 'users', static::LEVELDB_BUCKET_TYPE)
            ->build();

        $response = $command->execute($command);

        $this->assertEquals('200', $response->getStatusCode());
        $this->assertInstanceOf('Basho\Riak\Object', $response->getObject());
        $this->assertEquals('person', $response->getObject()->getData());
        $this->assertNotEmpty($response->getVClock());
        $indexes = $response->getObject()->getIndexes();
        $this->assertEquals($indexes['lucky_numbers_int'], [42, 64]);
        $this->assertEquals($indexes['lastname_bin'], ['Knuth']);

        static::$object = $response->getObject();
        static::$vclock = $response->getVclock();
    }

    /**
     * @depends      testStoreObjectWithIndexes
     * @dataProvider getLocalNodeConnection
     *
     * @param $riak \Basho\Riak
     */
    public function testRemoveIndexes($riak)
    {
        $object = static::$object;
        $object->removeValueFromIndex('lucky_numbers_int', 64);
        $object->removeValueFromIndex('lastname_bin', 'Knuth');

        $command = (new Command\Builder\StoreObject($riak))
            ->withObject($object)
            ->buildLocation(static::$key, 'users', static::LEVELDB_BUCKET_TYPE)
            ->withHeader("X-Riak-Vclock", static::$vclock)
            ->build();

        // TODO: internalize Vclock to Riak\Object.

        $response = $command->execute($command);

        $this->assertEquals('204', $response->getStatusCode());

        $command = (new Command\Builder\FetchObject($riak))
            ->buildLocation(static::$key, 'users', static::LEVELDB_BUCKET_TYPE)
            ->build();

        $response = $command->execute($command);

        $this->assertEquals('200', $response->getStatusCode());
        $this->assertInstanceOf('Basho\Riak\Object', $response->getObject());
        $this->assertEquals('person', $response->getObject()->getData());
        $indexes = $response->getObject()->getIndexes();
        $this->assertEquals($indexes['lucky_numbers_int'], [42]);
    }
}