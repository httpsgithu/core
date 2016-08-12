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

use Sabre\HTTP\RequestInterface;
use Sabre\DAV\Exception\BadRequest;

class Bundle
{
    /**
     * @var \Sabre\HTTP\RequestInterface
     */
    protected $request;

    /**
     * @var integer
     */
    protected $cursor;

    /**
     * @var string|resource
     */
    protected $content = null;

    /**
     * Constructor.
     *
     * @param Request $request
     */
    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
        $this->cursor = 0;
    }

    /**
     * Get a line.
     *
     * If false is return, it's the end of file.
     *
     * @return string|boolean
     */
    public function gets()
    {
        $content = $this->getContent();
        if (is_resource($content)) {
            $line = fgets($content);
            $this->cursor = ftell($content);

            return $line;
        }

        $next = strpos($content, "\r\n", $this->cursor);
        $eof = $next < 0 || $next === false;

        if ($eof) {
            $line = substr($content, $this->cursor);
        } else {
            $length = $next - $this->cursor + strlen("\r\n");
            $line = substr($content, $this->cursor, $length);
        }

        $this->cursor = $eof ? -1 : $next + strlen("\r\n");

        return $line;
    }

    /**
     * @return int
     */
    public function getCursor()
    {
        return $this->cursor;
    }

    /**
     * Is end of file ?
     *
     * @return bool
     */
    public function eof()
    {
        return $this->cursor == -1 || (is_resource($this->getContent()) && feof($this->getContent()));
    }

    /**
     * Get request content.
     *
     * @return resource|string
     * @throws \RuntimeException
     */
    public function getContent()
    {
        if ($this->content === null) {
            $this->content = $this->request->getBody();

            if (!$this->content) {
                //TODO: handle exception PROPERLY
                throw new BadRequest('Unable to get request content');
            }
        }

        return $this->content;
    }
}