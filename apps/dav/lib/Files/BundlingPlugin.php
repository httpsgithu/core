<?php
/**
 * @author Piotr Mrowczynski <Piotr.Mrowczynski@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Files;

use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\Forbidden;


/**
 * TODO: plugin description
 *
 */
class BundlingPlugin extends ServerPlugin {

	/**
	 * Reference to main server object
	 *
	 * @var \Sabre\DAV\Server
	 */
	private $server;

	/**
	 * @var Bundle
	 */
	private $contentHandler = null;

	/**
	 * @var Bool
	 */
	private $endDelimiterReached = false;
	/**
	 * @param TODO: add required parapeters if needed
	 */
	public function __construct() {
	}

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the requires event subscriptions.
	 *
	 * @param \Sabre\DAV\Server $server
	 * @return void
	 */
	public function initialize(\Sabre\DAV\Server $server) {

		$this->server = $server;

		$server->on('method:POST', array($this, 'handleBundledUpload'));
	}

	/**
	 * We intercept this to handle method:POST on a dav resource and process the bundled files multipart HTTP request.
	 *
	 * @param RequestInterface $request
	 * @param ResponseInterface $response
	 * @return null|false
	 * @throws TODO: add possible exceptions to be thrown
	 */
	public function handleBundledUpload(RequestInterface $request, ResponseInterface $response) {
		// Making sure the node exists
		$path = $request->getPath();
		try {
			$node = $this->server->tree->getNodeForPath($path);
		} catch (NotFound $e) {
			//TODO: handle possible not existing user, but client authenticated earlier.
			return;
		}

		// Check that needed headers exists
		//TODO: add required fields and validation e.g. Content-Length or max number of files in one upload after architectural decision
		$this->checkHeaders($request, array('Content-Type'));

		$formData = $this->getFormData($request);

		while(!$this->endDelimiterReached){
			//TODO: validate that content is expected, contains client-ID and store in OC
			list($boundaryContentHeader, $boundaryContent) = $this->getContent($request);
		}

		//TODO: send multistatus response for each file
		$response->setStatus(200);
		return False;
	}

	/**
	 * Check multipart headers.
	 *
	 * @param  RequestInterface $request
	 * @param  array $headers
	 * @throws TODO: handle exception PROPERLY
	 */
	protected function checkHeaders(RequestInterface $request, array $headers = array()) {
		foreach ($headers as $header) {
			$value = $request->getHeader($header);
			if ($value === null) {
				//TODO:HANDLE EXCEPTION PROPERLY
				throw new Forbidden(sprintf('%s header is needed', $header));
			} elseif (!is_int($value) && empty($value)) {
				//TODO:HANDLE EXCEPTION PROPERLY
				throw new Forbidden(sprintf('%s header must not be empty', $header));
			}
		}

		//TODO: add suport for validation of more fields than only Content-Type, if needed
		list($contentType) = $this->parseContentTypeAndBoundary($request);

		$expectedContentType = 'multipart/related';
		if ($contentType != $expectedContentType) {
			//TODO: handle exception PROPERLY
			throw new Forbidden(sprintf(
					'Content-Type must be %s',
					$expectedContentType
			));
		}

	}

	/**
	 * Parse the content type and boudary from Content-Type header.
	 *
	 * @param  RequestInterface $request
	 * @return array
	 * @throws TODO: handle exception PROPERLY
	 */
	protected function parseContentTypeAndBoundary(RequestInterface $request)
	{
		$contentParts = explode(';', $request->getHeader('Content-Type'));
		if (count($contentParts) != 2) {
			//TODO:handle boundrary exception
			throw new Forbidden('Boundary may be missing');
		}

		$contentType = trim($contentParts[0]);
		$boundaryPart = trim($contentParts[1]);

		$shouldStart = 'boundary=';
		if (substr($boundaryPart, 0, strlen($shouldStart)) != $shouldStart) {
			//TODO:handle boundrary exception
			throw new Forbidden('Boundary is not set');
		}

		$boundary = substr($boundaryPart, strlen($shouldStart));
		if (substr($boundary, 0, 1) == '"' && substr($boundary, -1) == '"') {
			$boundary = substr($boundary, 1, -1);
		}

		return array($contentType, $boundary);
	}

	/**
	 * Get the form data from the request.
	 *
	 * Note: MUST be called before getContent, and just one time.
	 *
	 * @param  RequestInterface $request
	 * @throws TODO:handle boundrary exception
	 * @return array
	 */
	protected function getFormData(RequestInterface $request)
	{
		list($boundaryContentHeader, $boundaryContent) = $this->getPart($request);
		if ($boundaryContentHeader === null && $boundaryContent === null){
			//TODO: handle exception PROPERLY
			throw new Forbidden('Empty bundle form found');
		}

		$expectedContentType = 'application/json';
		if (array_key_exists('content-type', $boundaryContentHeader) && substr($boundaryContentHeader['content-type'], 0, strlen($expectedContentType)) != $expectedContentType) {
			//TODO: handle exception PROPERLY
			throw new Forbidden(sprintf(
				'Expected content type of first part is %s. Found %s',
				$expectedContentType,
				$boundaryContentHeader['content-type']
			));
		}

		$jsonContent = json_decode($boundaryContent, true);
		if ($jsonContent === null) {
			//TODO: handle exception PROPERLY
			throw new Forbidden('Unable to parse JSON');
		}

		return $jsonContent;
	}

	/**
	 * Get the content part of the request.
	 *
	 * Note: MUST be called after getFormData, and just one time.
	 *
	 * @param  RequestInterface $request
	 * @return array
	 */
	protected function getContent(RequestInterface $request)
	{
		return $this->getPart($request);
	}

	/**
	 * Get a part of request.
	 *
	 * @param  RequestInterface $request
	 * @throws TODO: handle exception
	 * @return array
	 */
	protected function getPart(RequestInterface $request)
	{
		list($contentType, $boundary) = $this->parseContentTypeAndBoundary($request);
		$content = $this->getRequestPart($request, $boundary);

		if (empty($content)) {
			//TODO: handle exception PROPERLY
			throw new Forbidden('Received an empty content part');
		}

		$headerLimitation = strpos($content, "\r\n\r\n") + 1;
		if ($headerLimitation == -1) {
			//TODO: handle exception PROPERLY
			throw new Forbidden('Unable to determine headers limit for content part');
		}

		$headersContent = substr($content, 0, $headerLimitation);
		$headersContent = trim($headersContent);
		$body = substr($content, $headerLimitation);
		$body = trim($body);

		foreach (explode("\r\n", $headersContent) as $header) {
			$parts = explode(':', $header);
			if (count($parts) != 2) {
				continue;
			}
			$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
		}

		return array($headers, $body);
	}

	/**
	 * Get part of a resource.
	 *
	 * @param  RequestInterface $request
	 * @param $boundary
	 * @throws TODO: handle exception PROPERLY
	 * @return string
	 */
	protected function getRequestPart(RequestInterface $request, $boundary)
	{
		$contentHandler = $this->getRequestContentHandler($request);

		$delimiter = '--'.$boundary."\r\n";
		$endDelimiter = '--'.$boundary.'--';
		$boundaryCount = 0;
		$content = '';
		while (!$contentHandler->eof()) {
			$line = $contentHandler->gets();
			if ($line === false) {
				//TODO: handle exception PROPERLY
				throw new Forbidden('An error appears while reading input in content part');
			}

			if ($boundaryCount == 0) {
				if ($line != $delimiter) {
					if ($contentHandler->getCursor() == strlen($line)) {
						//TODO: handle exception PROPERLY
						throw new Forbidden('Expected boundary delimiter in content part');
					}
				} else {
					continue;
				}

				$boundaryCount++;
			} elseif ($line == $delimiter) {
				break;
			} elseif ($line == $endDelimiter || $line == $endDelimiter."\r\n") {
				$this->endDelimiterReached = true;
				break;
			}

			$content .= $line;
		}

		return trim($content);
	}

	/**
	 * Get a request content handler.
	 *
	 * @param  RequestInterface $request
	 * @return Bundle
	 */
	protected function getRequestContentHandler(RequestInterface $request)
	{
		if ($this->contentHandler === null) {
			$this->contentHandler = new Bundle($request);
		}

		return $this->contentHandler;
	}
}