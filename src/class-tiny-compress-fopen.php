<?php
/*
* Tiny Compress Images - WordPress plugin.
* Copyright (C) 2015-2016 Voormedia B.V.
*
* This program is free software; you can redistribute it and/or modify it
* under the terms of the GNU General Public License as published by the Free
* Software Foundation; either version 2 of the License, or (at your option)
* any later version.
*
* This program is distributed in the hope that it will be useful, but WITHOUT
* ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
* FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
* more details.
*
* You should have received a copy of the GNU General Public License along
* with this program; if not, write to the Free Software Foundation, Inc., 51
* Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

class Tiny_Compress_Fopen extends Tiny_Compress {
	protected function __construct($api_key, $after_compress_callback) {
		parent::__construct( $after_compress_callback );

		$this->last_error_code = 0;
		$this->api_key = $api_key;
	}

	public function can_create_key() {
		return false;
	}

	public function get_compression_count() {
		return null;
	}

	public function get_key() {
		return $this->api_key;
	}

	public function limit_reached() {
		return $this->last_error_code == 429;
	}

	protected function validate() {
		$params = $this->request_options( 'POST' );
		list($details, $headers, $status_code) = $this->request( $params );

		if ( $status_code == 429 || $status_code == 400 ) {
			return true;
		} else {
			throw new Tiny_Exception(
				$details['message'],
				'Tinify\Exception',
				$status_code
			);
		}
	}

	protected function compress($input, $resize_opts, $preserve_opts) {
		$params = $this->request_options( 'POST', $input );
		list($details, $headers, $status_code) = $this->request( $params );

		$output_url = isset( $headers['location'] ) ? $headers['location'] : null;
		if ( isset( $details['error'] ) && $details['error'] ) {
			throw new Tiny_Exception(
				$details['message'],
				'Tinify\Exception',
				$status_code
			);
		} else if ( $status_code >= 400 ) {
			throw new Tiny_Exception(
				'Unexpected error during compression',
				'Tinify\Exception',
				$status_code
			);
		} else if ( $output_url === null ) {
			throw new Tiny_Exception(
				'Could not find output location',
				'Tinify\Exception'
			);
		}

		$params = $this->output_request_options( $resize_opts, $preserve_opts );
		list($output, $headers, $status_code) = $this->request( $params, $output_url );

		if ( isset( $output['error'] ) && $output['error'] ) {
			throw new Tiny_Exception(
				$output['message'],
				'Tinify\Exception',
				$status_code
			);
		} else if ( $status_code >= 400 ) {
			throw new Tiny_Exception(
				'Unexpected error during output retrieval',
				'Tinify\Exception',
				$status_code
			);
		}

		if ( is_string( $output ) && 0 == strlen( $output ) ) {
			throw new Tiny_Exception(
				'Could not download output',
				'Tinify\Exception'
			);
		}

		$meta = array(
			'input' => array(
				'size' => strlen( $input ),
				'type' => $headers['content-type'],
			),
			'output' => array(
				'size' => strlen( $output ),
				'type' => $headers['content-type'],
				'width' => intval( $headers['image-width'] ),
				'height' => intval( $headers['image-height'] ),
				'ratio' => round( strlen( $output ) / strlen( $input ), 4 ),
			)
		);

		return array( $output, $meta );
	}

	private function request($params, $url = Tiny_Config::URL) {
		$context = stream_context_create( $params );
		$request = fopen( $url, 'rb', false, $context );

		if ( ! $request ) {
			throw new Tiny_Exception(
				'Could not execute request, enable cURL for detailed errors',
				'Tinify\FopenError'
			);
		}

		$meta_data = stream_get_meta_data( $request );
		$headers = iterator_to_array( $meta_data['wrapper_data'] );

		$status_code = $this->parse_status_code( $headers );
		$headers = $this->parse_headers( $headers );

		$this->last_error_code = $status_code;

		$response = stream_get_contents( $request );
		fclose( $request );

		if ( isset( $headers['content-type'] ) && substr( 'application/json' == $headers['content-type'], 0, 16 ) ) {
			$response = $this->decode( $response );
		}

		return array( $response, $headers, $status_code );
	}

	private function parse_status_code($header) {
		if ( $header && count( $header ) > 0 ) {
			$http_code_values = explode( ' ', $header[0] );
			if ( count( $http_code_values ) > 1 ) {
				return intval( $http_code_values[1] );
			}
		}
		return null;
	}

	private function parse_headers($headers) {
		if ( ! is_array( $headers ) ) {
			$headers = explode( "\r\n", $headers );
		}
		$res = array();
		foreach ( $headers as $header ) {
			$split = explode( ':', $header, 2 );
			if ( 2 === count( $split ) ) {
				$res[ strtolower( $split[0] ) ] = trim( $split[1] );
			}
		}
		return $res;
	}

	private function request_options($method, $body = null) {
		return array(
			'http' => array(
				'method' => $method,
				'header' => array(
					'Content-type: image/png',
					'Authorization: Basic ' . base64_encode( 'api:' . $this->api_key ),
					'User-Agent: ' . Tiny_WP_Base::plugin_identification() . ' fopen',
				 ),
				'content' => $body,
				'follow_location' => 0,
				'max_redirects' => 1, // Necessary for PHP 5.2
				'ignore_errors' => true, // Apparently, a 201 is a failure
			),
			'ssl' => array(
				'cafile' => $this->get_ca_file(),
				'verify_peer' => true,
			)
		);
	}

	private function output_request_options($resize_opts, $preserve_opts) {
		$body = array();

		if ( $preserve_opts ) {
			$body['preserve'] = $preserve_opts;
		}

		if ( $resize_opts ) {
			$body['resize'] = $resize_opts;
		}

		if ( $resize_opts || $preserve_opts ) {
			$options['http']['header'][] = 'Content-Type: application/json';
			return $this->request_options( 'GET', json_encode( $body ) );
		} else {
			return $this->request_options( 'GET', null );
		}
	}

	private static function get_ca_file() {
		return dirname( __FILE__ ) . '/data/cacert.pem';
	}

	private static function decode($text) {
		$result = json_decode( $text, true );
		if ( $result === null ) {
			$message = sprintf(
				'JSON: %s [%d]',
				(PHP_VERSION_ID >= 50500 ? json_last_error_msg() : 'Unknown error'),
				(PHP_VERSION_ID >= 50300 ? json_last_error() : 'Error')
			);

			throw new Tiny_Exception( $message, 'JsonError' );
		}
		return $result;
	}
}
