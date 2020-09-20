<?php
/*
* File:     Attachment.php
* Category: -
* Author:   M. Goldenbaum
* Created:  16.03.18 19:37
* Updated:  -
*
* Description:
*  -
*/

namespace Webklex\PHPIMAP;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Webklex\PHPIMAP\Exceptions\MaskNotFoundException;
use Webklex\PHPIMAP\Exceptions\MethodNotFoundException;
use Webklex\PHPIMAP\Support\Masks\AttachmentMask;

/**
 * Class Attachment
 *
 * @package Webklex\PHPIMAP
 *
 * @property integer part_number
 * @property integer size
 * @property string content
 * @property string type
 * @property string content_type
 * @property string id
 * @property string name
 * @property string disposition
 * @property string img_src
 *
 * @method integer getPartNumber()
 * @method integer setPartNumber(integer $part_number)
 * @method string  getContent()
 * @method string  setContent(string $content)
 * @method string  getType()
 * @method string  setType(string $type)
 * @method string  getContentType()
 * @method string  setContentType(string $content_type)
 * @method string  getId()
 * @method string  setId(string $id)
 * @method string  getSize()
 * @method string  setSize(integer $size)
 * @method string  getName()
 * @method string  getDisposition()
 * @method string  setDisposition(string $disposition)
 * @method string  setImgSrc(string $img_src)
 */
class Attachment {

    /** @var Message $oMessage */
    protected $oMessage;

    /** @var array $config */
    protected $config = [];

    /** @var Part $part */
    protected $part;

    /** @var array $attributes */
    protected $attributes = [
        'content' => null,
        'type' => null,
        'content_type' => null,
        'id' => null,
        'name' => null,
        'disposition' => null,
        'img_src' => null,
        'size' => null,
    ];

    /**
     * Default mask
     * @var string $mask
     */
    protected $mask = AttachmentMask::class;

    /**
     * Attachment constructor.
     *
     * @param Message   $oMessage
     * @param Part      $part
     */
    public function __construct(Message $oMessage, Part $part) {
        $this->config = ClientManager::get('options');

        $this->oMessage = $oMessage;
        $this->part = $part;

        $default_mask = $this->oMessage->getClient()->getDefaultAttachmentMask();
        if($default_mask != null) {
            $this->mask = $default_mask;
        }

        $this->findType();
        $this->fetch();
    }

    /**
     * Call dynamic attribute setter and getter methods
     * @param string $method
     * @param array $arguments
     *
     * @return mixed
     * @throws MethodNotFoundException
     */
    public function __call($method, $arguments) {
        if(strtolower(substr($method, 0, 3)) === 'get') {
            $name = Str::snake(substr($method, 3));

            if(isset($this->attributes[$name])) {
                return $this->attributes[$name];
            }

            return null;
        }elseif (strtolower(substr($method, 0, 3)) === 'set') {
            $name = Str::snake(substr($method, 3));

            $this->attributes[$name] = array_pop($arguments);

            return $this->attributes[$name];
        }

        throw new MethodNotFoundException("Method ".self::class.'::'.$method.'() is not supported');
    }

    /**
     * @param $name
     * @param $value
     *
     * @return mixed
     */
    public function __set($name, $value) {
        $this->attributes[$name] = $value;

        return $this->attributes[$name];
    }

    /**
     * @param $name
     *
     * @return mixed|null
     */
    public function __get($name) {
        if(isset($this->attributes[$name])) {
            return $this->attributes[$name];
        }

        return null;
    }

    /**
     * Determine the structure type
     */
    protected function findType() {
        switch ($this->part->type) {
            case IMAP::ATTACHMENT_TYPE_MESSAGE:
                $this->type = 'message';
                break;
            case IMAP::ATTACHMENT_TYPE_APPLICATION:
                $this->type = 'application';
                break;
            case IMAP::ATTACHMENT_TYPE_AUDIO:
                $this->type = 'audio';
                break;
            case IMAP::ATTACHMENT_TYPE_IMAGE:
                $this->type = 'image';
                break;
            case IMAP::ATTACHMENT_TYPE_VIDEO:
                $this->type = 'video';
                break;
            case IMAP::ATTACHMENT_TYPE_MODEL:
                $this->type = 'model';
                break;
            case IMAP::ATTACHMENT_TYPE_TEXT:
                $this->type = 'text';
                break;
            case IMAP::ATTACHMENT_TYPE_MULTIPART:
                $this->type = 'multipart';
                break;
            default:
                $this->type = 'other';
                break;
        }
    }

    /**
     * Fetch the given attachment
     */
    protected function fetch() {

        $content = $this->part->content;

        $this->content_type = $this->type.'/'.strtolower($this->part->subtype);
        $this->content = $this->oMessage->decodeString($content, $this->part->encoding);

        if (($id = $this->part->id) !== null) {
            $this->id = str_replace(['<', '>'], '', $id);
        }

        $this->size = $this->part->bytes;

        if (($name = $this->part->name) !== null) {
            $this->setName($name);
            $this->disposition = $this->part->disposition;
        }elseif (($filename = $this->part->filename) !== null) {
            $this->setName($filename);
            $this->disposition = $this->part->disposition;
        }

        if (IMAP::ATTACHMENT_TYPE_MESSAGE == $this->part->type) {
            if ($this->part->ifdescription) {
                $this->setName($this->part->description);
            } else {
                $this->setName($this->part->subtype);
            }
        }
    }

    /**
     * Save the attachment content to your filesystem
     *
     * @param string $path
     * @param string|null $filename
     *
     * @return boolean
     */
    public function save($path, $filename = null) {
        $filename = $filename ?: $this->getName();

        return File::put($path.$filename, $this->getContent()) !== false;
    }

    /**
     * @param $name
     */
    public function setName($name) {
        $decoder = $this->config['decoder']['attachment'];
        if ($name !== null) {
            if($decoder === 'utf-8' && extension_loaded('imap')) {
                $this->name = \imap_utf8($name);
            }else{
                $this->name = mb_decode_mimeheader($name);
            }
        }
    }

    /**
     * @return string|null
     */
    public function getMimeType(){
        return (new \finfo())->buffer($this->getContent(), FILEINFO_MIME_TYPE);
    }

    /**
     * @return string|null
     */
    public function getExtension(){
        return ExtensionGuesser::getInstance()->guess($this->getMimeType());
    }

    /**
     * @return array
     */
    public function getAttributes(){
        return $this->attributes;
    }

    /**
     * @return Message
     */
    public function getMessage(){
        return $this->oMessage;
    }

    /**
     * @param $mask
     * @return $this
     */
    public function setMask($mask){
        if(class_exists($mask)){
            $this->mask = $mask;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getMask(){
        return $this->mask;
    }

    /**
     * Get a masked instance by providing a mask name
     * @param string|null $mask
     *
     * @return mixed
     * @throws MaskNotFoundException
     */
    public function mask($mask = null){
        $mask = $mask !== null ? $mask : $this->mask;
        if(class_exists($mask)){
            return new $mask($this);
        }

        throw new MaskNotFoundException("Unknown mask provided: ".$mask);
    }
}