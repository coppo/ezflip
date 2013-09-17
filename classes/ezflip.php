<?php

class eZFlip
{
	public $FlipINI;
    public $SiteINI;
    public $attribute;
    public $files = array();
    public $generateContentObjectImages;

    protected $cli;
    protected $isConverted;
    protected $flipObjectDirectory;
    protected $flipVarDirectory;
    protected $flipObjectDirectoryName;
    protected $pdftkExecutable;
    protected $gsExecutable;

    /*
     * eZFlip constructor
     * @var eZContentObjectAttribute
     */
	public function __construct( eZContentObjectAttribute $attribute, $useCli = false )
    {
        $this->SiteINI = eZINI::instance();
        $this->FlipINI = eZINI::instance( 'ezflip.ini' );

        $this->pdftkExecutable = $this->FlipINI->variable( 'HelperSettings', 'PdftkExecutablePath' );
        $this->gsExecutable = $this->FlipINI->variable( 'HelperSettings', 'GhostscriptExecutablePath' );
        if( $useCli )
        {
            $this->checkDependencies();
        }

        if ( !$attribute instanceof eZContentObjectAttribute )
        {
            throw new Exception( "Object isn't a eZContentObjectAttribute" );
        }

        if ( !$attribute->attribute( 'has_content' ) )
        {
            throw new Exception( "Attribute is empty" );
        }

        if ( $attribute->attribute( 'data_type_string' ) != 'ezbinaryfile' )
        {
            throw new Exception( "Attribute isn't a ezbinaryfile" );
        }

        if ( $attribute->attribute( 'content' )->attribute( 'mime_type' ) != 'application/pdf' )
        {
            throw new Exception( "File isn't a PDF file" );
        }

        $this->cli = $useCli ? eZCLI::instance() : false;

        $this->attribute = $attribute;

        $this->generateContentObjectImages = (bool) $this->FlipINI->variable( 'FlipSettings', 'GenerateContentObjectImages' ) == 'enabled';

        $this->flipVarDirectory = $this->SiteINI->variable( 'FileSettings','VarDir' ) . '/storage/original/application_flip';
        $this->generateSymLink();
        //@todo make flip folder versioned
        //$this->flipObjectDirectoryName = $this->attribute->attribute( 'id' ) . '-' . $this->attribute->attribute( 'version' );
        $this->flipObjectDirectoryName = $this->attribute->attribute( 'id' );
        $this->flipObjectDirectory = $this->flipVarDirectory . '/' . $this->flipObjectDirectoryName;
        $this->readFiles();
        return $this;
    }

    public function checkDependencies()
    {
        $command = "{$this->pdftkExecutable} --version";
        exec( $command, $resultPdftk );
        if ( empty( $resultPdftk )  )
        {
            throw new Exception( 'pdftk not found. Install it from http://www.pdflabs.com/tools/pdftk-the-pdf-toolkit/' );
        }

        $command = "{$this->gsExecutable} --version";
        exec( $command, $resultGs );
        if ( empty( $resultGs ) )
        {
            throw new Exception( 'Ghostscript not found.' );
        }
    }

    /*
     * Return the relative directory used by flip_dir template operator
     * This is a workaround to MegaZine3
     * @see generateSymLink
     */
    public function getFlipDirectory()
    {
        $ini = eZINI::instance( 'site.ini' );
        $varDir = $ini->variable( 'FileSettings','VarDir' );
        return basename( $varDir ) . '/' . $this->flipObjectDirectoryName;
    }

    /*
     * Generate a symlink from application_flip var dir to megazine folder
     */
    protected function generateSymLink()
    {
        eZDir::mkdir( $this->flipVarDirectory, false, true );
        if ( !is_dir( $this->flipVarDirectory ) )
        {
            throw new Exception( 'Can not create directory ' .  $this->flipVarDirectory );
        }
        $megazineSymlink =  'extension/ezflip/design/standard/flash/megazine/'
                            . basename( $this->SiteINI->variable( 'FileSettings','VarDir' ) );

        if ( !is_link( $megazineSymlink ) )
        {
            if ( !eZFileHandler::symlink( eZSys::rootDir() . eZSys::fileSeparator() . $this->flipVarDirectory, $megazineSymlink ) )
            {
                throw new Exception( 'Can not create symlink of ' . $this->flipVarDirectory . ' in ' . $megazineSymlink );
            }
        }
    }

    /*
     * Create the object directory in flip var dir
     * Call the eZFlipPdfHandler to split pdf in images in the object flip var dir
     */
    protected  function preparePdf()
    {
        eZDir::recursiveDelete( $this->flipObjectDirectory );
        eZDir::mkdir( $this->flipObjectDirectory, false, true );
        if ( !is_dir( $this->flipObjectDirectory ) )
        {
            throw new Exception( 'Can not create directory ' . $this->flipObjectDirectory );
        }

        $storedFile = $this->attribute->storedFileInformation( false, false, false );;
        $storedFilePath = $this->SiteINI->variable( 'FileSettings','VarDir' ) . '/' . $storedFile['filepath'];
        eZFlipPdfHandler::splitPDFPages( $this->flipObjectDirectory, $storedFilePath, $this->cli );
        $this->readFiles();
        return $this;
    }

	protected function readFiles()
	{
        $fileList = array();
        eZDir::recursiveList( $this->flipObjectDirectory, $this->flipObjectDirectory, $fileList );
        foreach( $fileList as $item )
        {
            if ( $item['type'] == 'file' && eZFile::suffix( $item['name'] ) == 'pdf' && !in_array( $item['name'], $this->files ) )
            {
                $this->files[] = $item['name'];
            }
        }
    }

    protected function createImages()
    {
        if ( $this->generateContentObjectImages )
        {
            eZFlipImageHandler::deleteThumb( $this->attribute->attribute( 'object' )->attribute( 'main_node_id' ), $this->cli );
        }
        $sizes = $this->FlipINI->variable( 'FlipSettings', 'SizeThumb');
        $sizesOptions = $this->FlipINI->variable( 'FlipSettings', 'SizeThumbOptions');

        $i = 0;
        foreach( $this->files as $file )
        {
            $i++;
            foreach ( $sizes as $size )
            {
                $options = '';
                if ( isset( $sizesOptions[$size] ) )
                {
                    $options = $sizesOptions[$size];
                }

                $pageName = self::generatePageFileName( $i, $size );
                eZFlipPdfHandler::createImageFromPDF( $size, $this->flipObjectDirectory, $file, $pageName, $options, $this->cli );

                $ratio = getimagesize( $this->flipObjectDirectory . '/' . $pageName );
                if ( !is_array( $ratio ) )
                {
                    throw new Exception( 'failed creating ' . $pageName );
                }

                if ( $this->generateContentObjectImages )
                {
                    eZFlipImageHandler::createThumb( $this->flipObjectDirectory,
                                                     $pageName,
                                                     $this->attribute->attribute( 'object' )->attribute( 'main_node_id' ) );
                }
            }
        }
        $this->deletePDFFiles();
        return $this;
    }

    protected function deletePDFFiles()
    {
        foreach( $this->files as $fileName )
        {
            $file = eZClusterFileHandler::instance( $this->flipObjectDirectory . "/" . $fileName );
            if ( $file->exists() )
            {
                $file->delete();
            }
        }
    }

    public static function generatePageFileName( $index, $size, $suffix = 'jpg' )
    {
        return "page" . sprintf( "%04d", $index ) . "_" . $size . "." . $suffix;
    }

    protected function createBook()
    {
        $sizes = $this->FlipINI->variable( 'FlipSettings', 'SizeThumb');
        $books = $this->FlipINI->variable( 'FlipBookSettings', 'FlipBook');
        foreach ( $books as $book )
        {
            $args = $this->FlipINI->variable( 'FlipBookSettings_' . $book, 'FlipBookSettings_' . $book);
            $ratio = getimagesize( $this->flipObjectDirectory . '/' . self::generatePageFileName( 1, $sizes['large'] ) );
            if ( !is_array( $ratio ) )
            {
                throw new Exception( 'getimagesize return wrong value' );
            }
            $ratio = $ratio[1] / $ratio[0];
            $args['ratio'] = $ratio;

            $xml = eZFlipXmlHandler::openBook( $args );
            $i = 0;
            foreach ( $this->files as $file)
            {
                $i++;
                $xml .= eZFlipXmlHandler::writePage(
                    $i,
                    $sizes[$args['thumb_size']],
                    $sizes[$args['full_size']],
                    $this->getFlipDirectory()
                );
            }
            $xml .= eZFlipXmlHandler::closeBook();
            eZFile::create( "magazine_" . $book . ".xml", $this->flipObjectDirectory, $xml );
        }
        return $this;
    }

	public static function convert( $args, $cli = false )
    {
        $contentObjectAttribute = eZContentObjectAttribute::fetch( $args[0], $args[1] );
        if ( !$contentObjectAttribute instanceof eZContentObjectAttribute )
        {
            throw new Exception( 'Attribute not found' );
        }
        $ezFlip = new eZFlip( $contentObjectAttribute, $cli );

        $ezFlip->preparePdf()
            ->createImages()
            ->createBook();

        eZContentCacheManager::clearObjectViewCache( $contentObjectAttribute->attribute( 'contentobject_id' ) );

		return true;
    }

    /**
     * Check if user has flipd.
     *
     * @return bool
     */
    public function isConverted()
    {
		$books = $this->FlipINI->variable( 'FlipBookSettings', 'FlipBook');
		foreach ( $books as $book )
        {
            $file = eZClusterFileHandler::instance( $this->flipObjectDirectory . "/magazine_" . $book . ".xml" );
            if ( $file->exists() )
            {
                return true;
            }
        }
        eZDebug::writeNotice( 'File ' . $this->flipObjectDirectory . "/magazine_" . $book . ".xml" . ' not found' );
        return false;

    }

    /*
     * Read the page dimensions from xml book description
     *
     * @var string $bookName
     *
     * @return bool
     */
    public function getPageDimensions( $bookName )
    {
		$books = $this->FlipINI->variable( 'FlipBookSettings', 'FlipBook');
		foreach ( $books as $book )
        {
            if ( $book == $bookName )
            {
                $file = eZClusterFileHandler::instance( $this->flipObjectDirectory . "/magazine_" . $book . ".xml" );
                if ( $file->exists() )
                {
                    $xml = simplexml_load_file( $file->filePath );
                    $width = $xml['pagewidth'];
                    $height = $xml['pageheight'] + 100;
                    return array( $width, $height );
                }
            }
        }
        return false;

    }

}

?>