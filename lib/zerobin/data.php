<?php
/**
 * ZeroBin
 *
 * a zero-knowledge paste bin
 *
 * @link      http://sebsauvage.net/wiki/doku.php?id=php:zerobin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   http://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 * @version   0.22
 */

/**
 * zerobin_data
 *
 * Model for data access, implemented as a singleton.
 */
class zerobin_data extends zerobin_abstract
{
    /**
     * directory where data is stored
     *
     * @access private
     * @static
     * @var string
     */
    private static $_dir = 'data/';

    /**
     * get instance of singleton
     *
     * @access public
     * @static
     * @param  array $options
     * @return zerobin_data
     */
    public static function getInstance($options = null)
    {
        // if given update the data directory
        if (
            is_array($options) &&
            array_key_exists('dir', $options)
        ) self::$_dir = $options['dir'] . DIRECTORY_SEPARATOR;
        // if needed initialize the singleton
        if(!(self::$_instance instanceof zerobin_data)) {
            self::$_instance = new self;
            self::_init();
        }
        return self::$_instance;
    }

    /**
     * Create a paste.
     *
     * @access public
     * @param  string $pasteid
     * @param  array  $paste
     * @return bool
     */
    public function create($pasteid, $paste)
    {
        $storagedir = self::_dataid2path($pasteid);
        if (is_file($storagedir . $pasteid)) return false;
        if (!is_dir($storagedir)) mkdir($storagedir, 0705, true);
        return (bool) @file_put_contents($storagedir . $pasteid, json_encode($paste));
    }

    /**
     * Read a paste.
     *
     * @access public
     * @param  string $pasteid
     * @return stdClass|false
     */
    public function read($pasteid)
    {
        if(!$this->exists($pasteid)) return false;
        $paste = json_decode(
            file_get_contents(self::_dataid2path($pasteid) . $pasteid)
        );
        if (property_exists($paste->meta, 'attachment'))
        {
            $paste->attachment = $paste->meta->attachment;
            unset($paste->meta->attachment);
            if (property_exists($paste->meta, 'attachmentname'))
            {
                $paste->attachmentname = $paste->meta->attachmentname;
                unset($paste->meta->attachmentname);
            }
        }
        return $paste;
    }

    /**
     * Delete a paste and its discussion.
     *
     * @access public
     * @param  string $pasteid
     * @return void
     */
    public function delete($pasteid)
    {
        // Delete the paste itself.
        @unlink(self::_dataid2path($pasteid) . $pasteid);

        // Delete discussion if it exists.
        $discdir = self::_dataid2discussionpath($pasteid);
        if (is_dir($discdir))
        {
            // Delete all files in discussion directory
            $dir = dir($discdir);
            while (false !== ($filename = $dir->read()))
            {
                if (is_file($discdir.$filename)) @unlink($discdir.$filename);
            }
            $dir->close();

            // Delete the discussion directory.
            @rmdir($discdir);
        }
    }

    /**
     * Test if a paste exists.
     *
     * @access public
     * @param  string $dataid
     * @return void
     */
    public function exists($pasteid)
    {
        return is_file(self::_dataid2path($pasteid) . $pasteid);
    }

    /**
     * Create a comment in a paste.
     *
     * @access public
     * @param  string $pasteid
     * @param  string $parentid
     * @param  string $commentid
     * @param  array  $comment
     * @return bool
     */
    public function createComment($pasteid, $parentid, $commentid, $comment)
    {
        $storagedir = self::_dataid2discussionpath($pasteid);
        $filename = $pasteid . '.' . $commentid . '.' . $parentid;
        if (is_file($storagedir . $filename)) return false;
        if (!is_dir($storagedir)) mkdir($storagedir, 0705, true);
        return (bool) @file_put_contents($storagedir . $filename, json_encode($comment));
    }

    /**
     * Read all comments of paste.
     *
     * @access public
     * @param  string $pasteid
     * @return array
     */
    public function readComments($pasteid)
    {
        $comments = array();
        $discdir = self::_dataid2discussionpath($pasteid);
        if (is_dir($discdir))
        {
            // Delete all files in discussion directory
            $dir = dir($discdir);
            while (false !== ($filename = $dir->read()))
            {
                // Filename is in the form pasteid.commentid.parentid:
                // - pasteid is the paste this reply belongs to.
                // - commentid is the comment identifier itself.
                // - parentid is the comment this comment replies to (It can be pasteid)
                if (is_file($discdir . $filename))
                {
                    $comment = json_decode(file_get_contents($discdir . $filename));
                    $items = explode('.', $filename);
                    // Add some meta information not contained in file.
                    $comment->id = $items[1];
                    $comment->parentid = $items[2];

                    // Store in array
                    $key = $this->getOpenSlot($comments, (int) $comment->meta->postdate);
                    $comments[$key] = $comment;
                }
            }
            $dir->close();

            // Sort comments by date, oldest first.
            ksort($comments);
        }
        return $comments;
    }

    /**
     * Test if a comment exists.
     *
     * @access public
     * @param  string $dataid
     * @param  string $parentid
     * @param  string $commentid
     * @return void
     */
    public function existsComment($pasteid, $parentid, $commentid)
    {
        return is_file(
            self::_dataid2discussionpath($pasteid) .
            $pasteid . '.' . $commentid . '.' . $parentid
        );
    }

    /**
     * initialize zerobin
     *
     * @access private
     * @static
     * @return void
     */
    private static function _init()
    {
        // Create storage directory if it does not exist.
        if (!is_dir(self::$_dir)) mkdir(self::$_dir, 0705);
        // Create .htaccess file if it does not exist.
        if (!is_file(self::$_dir . '.htaccess'))
        {
            file_put_contents(
                self::$_dir . '.htaccess',
                'Allow from none' . PHP_EOL .
                'Deny from all'. PHP_EOL
            );
        }
    }

    /**
     * Convert paste id to storage path.
     *
     * The idea is to creates subdirectories in order to limit the number of files per directory.
     * (A high number of files in a single directory can slow things down.)
     * eg. "f468483c313401e8" will be stored in "data/f4/68/f468483c313401e8"
     * High-trafic websites may want to deepen the directory structure (like Squid does).
     *
     * eg. input 'e3570978f9e4aa90' --> output 'data/e3/57/'
     *
     * @access private
     * @static
     * @param  string $dataid
     * @return void
     */
    private static function _dataid2path($dataid)
    {
        return self::$_dir . substr($dataid,0,2) . '/' . substr($dataid,2,2) . '/';
    }

    /**
     * Convert paste id to discussion storage path.
     *
     * eg. input 'e3570978f9e4aa90' --> output 'data/e3/57/e3570978f9e4aa90.discussion/'
     *
     * @access private
     * @static
     * @param  string $dataid
     * @return void
     */
    private static function _dataid2discussionpath($dataid)
    {
        return self::_dataid2path($dataid) . $dataid . '.discussion/';
    }
}
