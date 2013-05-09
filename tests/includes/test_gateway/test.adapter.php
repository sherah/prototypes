<?php
/**
 * Wikimedia Foundation
 *
 * LICENSE
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 */

/**
 * TestingGlobalCollectAdapter
 *
 */
class TestingGlobalCollectAdapter extends GlobalCollectAdapter {
	/**
	 * Also set a useful MerchantID.
	 */
	public function __construct( $options = array() ) {
		parent::__construct( $options );
		$this->account_config['MerchantID'] = 'test';
	}

	/**
	 * Override the curl_transaction to use a local copy of a fake payment
	 * instead of actually contacting GlobalCollect
	 *
	 * @param string $data The data we're trying to send to a server
	 * @return boolean Whether the communication was successful.
	 */
	protected function curl_transaction( $data ) {
		$retval = false;
		$logPrefix = $this->getLogMessagePrefix();
		$email = $this->getData_Unstaged_Escaped( 'email' );
		$this->log( $logPrefix . "Initiating fake cURL request for donor $email" );

		// Run hooks
		$hookResult = wfRunHooks( 'DonationInterfaceCurlInit', array( &$this ) );
		if ( $hookResult === false ) {
			self::log( "$logPrefix fake cURL transaction aborted on hook
				DonationInterfaceCurlInit", LOG_INFO );
			$this->setValidationAction( 'reject' );
			return false;
		}

		// Construct fake response
		$results = array();

		// Get some DOM-looking things for the request body
		$dom = new SimpleXMLElement( $data );
		$request = array_shift( $dom->xpath( '/XML/REQUEST' ) );

		// Figure out the request type
		$action = array_shift( $request->xpath( 'ACTION' ) )->asXML();
		$action = preg_replace( '#^<ACTION>(.*)</ACTION>$#', '\\1', $action );

		if ( $action === 'INSERT_ORDERWITHPAYMENT' ) {
			// Why can't we use absolute paths here? No real reason to
			// spend time figuring it out.
			$order = array_shift( $request->xpath( 'PARAMS/ORDER' ) );
			$orderid = array_shift( $order->xpath( 'ORDERID' ) )->asXML();
			$orderid = preg_replace( '#^<ORDERID>(.*)</ORDERID>$#', '\\1', $orderid );
			$amount = array_shift( $order->xpath( 'AMOUNT' ) )->asXML();
			$amount = preg_replace( '#^<AMOUNT>(.*)</AMOUNT>$#', '\\1', $amount );
			$currency = array_shift( $order->xpath( 'CURRENCYCODE' ) )->asXML();
			$currency = preg_replace( '#^<CURRENCY>(.*)</CURRENCY>$#', '\\1', $currency );

			// Constants
			$refnum = '000000000000000000000000000000';
			$statusid = '20';
			$mac = 'maQKu1wA3aLG11UymxkvFHV2LbqLxZH12COp/JEZ/uo=';
			$datetime = date( 'YmdHis' );

			$formURI = '#';

			$response = $request->addChild( 'RESPONSE' );
			$response->addChild( 'RESULT', 'OK' );

			$meta = $response->addChild( 'META' );
			$meta->addChild( 'REQUESTID', '1891851' );
			$meta->addChild( 'RESPONSEDATETIME', $datetime );

			$row = $response->addChild( 'ROW' );
			$row->addChild( 'STATUSDATE', $datetime );
			$row->addChild( 'PAYMENTREFERENCE', '0' );
			$row->addChild( 'ADDITIONALREFERENCE', $orderid );
			$row->addChild( 'ORDERID', $orderid );
			$row->addChild( 'EXTERNALREFERENCE', $orderid );
			$row->addChild( 'EFFORTID', '1' );
			$row->addChild( 'REF', $refnum );
			$row->addChild( 'FORMACTION', $formURI );
			$row->addChild( 'FORMMETHOD', 'GET' );
			$row->addChild( 'ATTEMPTID', '1' );
			$row->addChild( 'MERCHANTID', $mercid );
			$row->addChild( 'STATUSID', $statusid );
			$row->addChild( 'RETURNMAC', 's1h645HHsQRpCEMpOa8IyfAEHtPig+N0cEYmt08LSrw=' );
			$row->addChild( 'MAC', $mac );

			$xmlresponse = $dom->asXML();
		}

		$results['result'] = (
			'HTTP/1.1 100 Continue\n\n' .

			'HTTP/1.1 200 OK\n' .
			'Server: Sun-ONE-Web-Server/6.1\n' .
			'Date: ' . date( 'r' ) . '\n' .
			'Content-length: ' . strlen( $xmlresponse ) . '\n' .
			'Content-type: text/xml; charset=utf-8\n' .
			'P3p: policyref="https://ps.gcsip.nl/w3c/policy.xml",CP="NON DSP CURa ADMa OUR NOR BUS IND PHY ONL UNI FIN COM NAV STA"\n' .
			'Cache-control: no-cache, no-store, must-revalidate, max-age=0, proxy-revalidate, no-transform, pre-check=0, post-check=0, private\n' .
			'Expires: Thu, Jan 01 1970 00:00:00 GMT\n' .
			'Pragma: no-cache\n\n' .

			$xmlresponse

		);

		// This assumes some things, and maybe bits aren't necessary.
		// Remove as needed.
		$results['headers'] = array(
			'url' => '',
			'content_type' => 'text/xml; charset=utf-8',
			'http_code' => 200,
			'header_size' => 483,
			'request_size' => 234,
			'filetime' => -1,
			'ssl_verify_result' => 0,
			'redirect_count' => 0,
			'total_time' => 1.439627,
			'namelookup_time' => 0.072232,
			'connect_time' => 0.219967,
			'pretransfer_time' => 0.685452,
			'size_upload' => strlen( $data ),
			'size_download' => strlen( $xmlresponse ),
			'speed_download' => 1307,
			'speed_upload' => 712,
			'download_content_length' => strlen( $xmlresponse ),
			'upload_content_length' => strlen( $data ),
			'starttransfer_time' => 0.835144,
			'redirect_time' => 0,
			'certinfo' => array()
		);

		$this->setTransactionResult( $results );
		return ( $results['headers']['http_code'] === 200 &&
			$results['result'] !== false );
	}

	/**
	 * Returns the variable $this->dataObj which should be an instance of
	 * DonationData.
	 *
	 * @returns DonationData
	 */
	public function getDonationData() {
		return $this->dataObj;
	}
}
