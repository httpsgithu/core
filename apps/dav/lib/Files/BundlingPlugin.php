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
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\BadRequest;


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
	 * TODO:
	 *
	 * @var \Sabre\HTTP\RequestInterface
	 */
	private $request;

	/**
	 * TODO:
	 *
	 * @var \Sabre\HTTP\ResponseInterface
	 */
	private $response;

	/**
	 * TODO:
	 *
	 * @var String
	 */
	private $boundary = null;

	/**
	 * @var \OCA\DAV\FilesBundle
	 */
	private $contentHandler = null;

	/**
	 * @var Array
	 */
	private $bundleMetadata = null;

	/**
	 * @var Bool
	 */
	private $endDelimiterReached = false;

	/**
	 * Plugin contructor
	 *
	 */
	public function __construct() {}

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
	 * @throws TODO: add possible exceptions to be thrown
	 * @return null|false
	 */
	public function handleBundledUpload(RequestInterface $request, ResponseInterface $response) {
		$this->request = $request;
		$this->response = $response;

		$this->validateRequest();

		$this->getBundleMetadata();

		$this->getBundleContents();

		return $this->sendResponse();
	}

	/**
	 * Get a part of request.
	 *
	 * @param  RequestInterface $request
	 * @param  String $boundary
	 * @throws TODO: handle exception
	 * @return array
	 */
	protected function getPart(RequestInterface $request, $boundary)
	{
		if ($this->contentHandler === null) {
			$this->contentHandler = new Bundle($request);
		}

		$delimiter = '--'.$boundary."\r\n";
		$endDelimiter = '--'.$boundary.'--';
		$boundaryCount = 0;
		$content = '';
		while (!$this->contentHandler->eof()) {
			$line = $this->contentHandler->gets();
			if ($line === false) {
				//TODO: handle exception PROPERLY
				throw new BadRequest('An error appears while reading input in content part');
			}

			if ($boundaryCount == 0) {
				if ($line != $delimiter) {
					if ($this->contentHandler->getCursor() == strlen($line)) {
						//TODO: handle exception PROPERLY
						throw new BadRequest('Expected boundary delimiter in content part');
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
		$content = trim($content);

		if (empty($content)) {
			//TODO: handle exception PROPERLY
			throw new BadRequest('Received an empty content part');
		}

		$headerLimitation = strpos($content, "\r\n\r\n") + 1;
		if ($headerLimitation == -1) {
			//TODO: handle exception PROPERLY
			throw new BadRequest('Unable to determine headers limit for content part');
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
	 * Check multipart headers.
	 *
	 * @throws TODO: handle exception PROPERLY
	 * @return void
	 */
	private function validateRequest() {

		// Making sure the end node exists
		//TODO: add support for user creation if that is first sync. Currently user has to be created.
		$path = $this->request->getPath();
		$this->server->tree->getNodeForPath($path);

		//TODO: validate if it has required headers
		$headers = array('Content-Type');
		foreach ($headers as $header) {
			$value = $this->request->getHeader($header);
			if ($value === null) {
				//TODO:HANDLE EXCEPTION PROPERLY
				throw new BadRequest(sprintf('%s header is needed', $header));
			} elseif (!is_int($value) && empty($value)) {
				//TODO:HANDLE EXCEPTION PROPERLY
				throw new BadRequest(sprintf('%s header must not be empty', $header));
			}
		}

		if (!$this->server->emit('beforeWriteBundle', [$path])){
			//TODO:handle exception
			throw new Forbidden('beforeWriteContent preconditions failed');
		}

		$contentParts = explode(';', $this->request->getHeader('Content-Type'));
		if (count($contentParts) != 2) {
			//TODO:handle exception
			throw new Forbidden('Improper Content-type format. Boundary may be missing');
		}

		//Validate content-type
		//TODO: add suport for validation of more fields than only Content-Type, if needed
		$contentType = trim($contentParts[0]);
		$expectedContentType = 'multipart/related';
		if ($contentType != $expectedContentType) {
			//TODO: handle exception PROPERLY
			throw new BadRequest(sprintf(
				'Content-Type must be %s',
				$expectedContentType
			));
		}

		//Validate boundrary
		$boundaryPart = trim($contentParts[1]);
		$shouldStart = 'boundary=';
		if (substr($boundaryPart, 0, strlen($shouldStart)) != $shouldStart) {
			//TODO:handle boundrary exception
			throw new BadRequest('Boundary is not set');
		}

		$boundary = substr($boundaryPart, strlen($shouldStart));
		if (substr($boundary, 0, 1) == '"' && substr($boundary, -1) == '"') {
			$boundary = substr($boundary, 1, -1);
		}
		$this->boundary = $boundary;
	}

	/**
	 * Get the bundle metadata from the request.
	 *
	 * Note: MUST be called before getBundleContent, and just one time.
	 *
	 * @throws TODO:handle boundrary exception
	 * @return void
	 */
	private function getBundleMetadata()
	{
		list($boundaryContentHeader, $boundaryContent) = $this->getPart($this->request, $this->boundary);

		if ($boundaryContentHeader === null && $boundaryContent === null){
			//TODO: handle exception PROPERLY
			throw new BadRequest('Empty bundle form found');
		}

		$expectedContentType = 'application/json';
		if (array_key_exists('content-type', $boundaryContentHeader) && substr($boundaryContentHeader['content-type'], 0, strlen($expectedContentType)) != $expectedContentType) {
			//TODO: handle exception PROPERLY
			throw new BadRequest(sprintf(
				'Expected content type of first part is %s. Found %s',
				$expectedContentType,
				$boundaryContentHeader['content-type']
			));
		}

		$jsonContent = json_decode($boundaryContent, true);
		if ($jsonContent === null) {
			//TODO: handle exception PROPERLY
			throw new BadRequest('Unable to parse JSON');
		}
		$this->bundleMetadata = $jsonContent;
	}

	/**
	 * Get the content part of the request.
	 *
	 * Note: MUST be called after getFormData, and just one time.
	 *
	 * @return void
	 */
	private function getBundleContents()
	{
		while(!$this->endDelimiterReached){
			list($boundaryContentHeader, $boundaryContent) = $this->getPart($this->request, $this->boundary);

			if (!isset($boundaryContentHeader['content-id'])){
				throw new BadRequest('Request contains part without Client-ID and multistatus response cannot be constructed');
			}
			$id = $boundaryContentHeader['content-id'];

			//check if that file is expected.
			if (!isset($this->bundleMetadata[$id])){
				throw new BadRequest(sprintf(
					'Request data block contains unexpected content with content-id %s. Corresponding metadata does not exists',
					$id
				));
			}

			//check if the expected file is corrupted
			//TODO: description below
			// Discuss if whole request should be aborded or not. The code below detects not only corruption of single file,
			//but also prevents from misuse of multipart/related protocol (it might not make sense to process request if all the files are not following protocol).
			$hash = md5($boundaryContent);
			if (!($hash === $id)){
				throw new BadRequest(sprintf(
					'Expected content hash is %s. Found %s',
					$id,
					$hash
				));
			}

			//TODO: store file, not just place in some random folder
			file_put_contents($this->bundleMetadata[$id]['filepath'],$boundaryContent);

			//TODO: metadata
		}
	}


	/**
	 * Send multipart response
	 *
	 * @return boolean
	 */
	private function sendResponse()
	{
		//TODO: send multistatus response for each file

		//multistatus response anounced
		$this->response->setStatus(207);

		return false;
	}

	/**
	 * Returns a bunch of meta-data about the plugin.
	 *
	 * Providing this information is optional, and is mainly displayed by the
	 * Browser plugin.
	 *
	 * The description key in the returned array may contain html and will not
	 * be sanitized.
	 *
	 * @return array
	 */
	function getPluginInfo() {

		return [
			'name'        => $this->getPluginName(),
			'description' => 'TODO:',
			'link'        => null,
		];

	}

	/**
	 * Returns a plugin name.
	 *
	 * Using this name other plugins will be able to access other plugins
	 * using DAV\Server::getPlugin
	 *
	 * @return string
	 */
	function getPluginName() {

		return 'bundling';

	}
}