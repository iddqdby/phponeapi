<?php

/*
 * The MIT License
 *
 * Copyright 2016 Sergey Protasevich.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace PHPOneAPI;

use PhpXmlRpc\Value as XMLRPCValue;
use PhpXmlRpc\Request as XMLRPCRequest;
use PhpXmlRpc\Client as XMLRPCClient;
use PHPOneAPI\Exception;


/**
 * Elementary client for OpenNebula XML-RPC API.
 *
 * The client use phpxmlrpc/phpxmlrpc library, refer to its documentation for more details.
 */
class Client {


    private $ssl;
    private $host;
    private $port;
    private $path;
    private $uri;
    private $username;
    private $password;
    private $client;


    /**
     * Create new instance of the client.
     *
     * @param string $username username
     * @param string $password password
     * @param string $host host
     * @param bool $ssl true to use https, false to use http (optional, default is true)
     * @param int $port port (optional, default is 2633)
     */
    public function __construct( $username, $password, $host, $ssl = true, $port = 2633, $path = 'RPC2' ) {

        $this->username = $username;
        $this->password = $password;
        $this->ssl = $ssl;
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;

        $this->uri = ( $ssl ? 'https' : 'http' ).'://'.$this->host.':'.$this->port.'/'.$this->path;

        $this->client = new XMLRPCClient( $this->uri );
    }


    /**
     * Get wrapped instance of \PhpXmlRpc\Client.
     *
     * @return \PhpXmlRpc\Client
     */
    public function getWrappedClient() {
        return $this->client;
    }


    /**
     * Call a method of OpenNebula XML-RPC API.
     *
     * @param string $method the method
     * @param array $args array of arguments
     * @throws \PHPOneAPI\Exception in case of an error
     * @return mixed result of the call
     */
    public function call( $method, array $args = [] ) {

        array_unshift( $args, new XMLRPCValue( $this->username.':'.$this->password ) );

        $args_prepared = array_map( function ( $arg ) {
            switch( gettype( $arg ) ) {
                case 'boolean':
                    return new XMLRPCValue( $arg, XMLRPCValue::$xmlrpcBoolean );
                case 'integer':
                    return new XMLRPCValue( $arg, XMLRPCValue::$xmlrpcInt );
                case 'double':
                    return new XMLRPCValue( $arg, XMLRPCValue::$xmlrpcDouble );
                case 'string':
                    return new XMLRPCValue( $arg, XMLRPCValue::$xmlrpcString );
                case 'array':

                    $is_struct = false;
                    foreach( array_keys( $arg ) as $key ) {
                        if( is_string( $key ) ) {
                            $is_struct = true;
                            break;
                        }
                    }

                    return new XMLRPCValue( $arg, $is_struct
                            ? XMLRPCValue::$xmlrpcStruct
                            : XMLRPCValue::$xmlrpcArray );

                case 'object':
                    return $arg instanceof XMLRPCValue
                            ? $arg
                            : new XMLRPCValue( strval( $arg ), XMLRPCValue::$xmlrpcString );
                case 'NULL':
                    return new XMLRPCValue( $arg, XMLRPCValue::$xmlrpcNull );
                default:
                    return new XMLRPCValue( strval( $arg ), XMLRPCValue::$xmlrpcString );
            }
        }, $args );

        $request = new XMLRPCRequest( $method, $args_prepared );

        $response = $this
            ->client
            ->send( $request );

        if( $response->faultCode() ) {
            throw new Exception( $response->faultString(), $response->faultCode() );
        }

        $result = $this->toArray( $response->value() );

        if( !$result[0] ) {
            throw new Exception( $result[1], $result[2] );
        }

        return $result[1];
    }


    public function __call( $method, array $arguments ) {
        $matches = [];
        preg_match( '/^(?<NAMESPACE>[a-z]+)(?<ACTION>[A-Z][a-z]+)$/u', $method, $matches );
        $action = 'one.'.@$matches['NAMESPACE'].'.'.strtolower( (string)@$matches['ACTION'] );
        return $this->call( $action, $arguments );
    }


    private function toArray( $val ) {

        if( $val instanceof XMLRPCValue && in_array( $val->kindOf(), [ 'scalar', 'undef' ] ) ) {
            return $val->scalarval();
        }

        if( $val instanceof \Traversable || is_array( $val ) ) {
            $array = [];
            foreach( $val as $key => $subval ) {
                $array[ $key ] = $this->toArray( $subval );
            }
            return $array;
        }

        return $val;
    }

}
