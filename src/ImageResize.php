<?php

namespace AJUR\Toolkit;

use Exception;

/**
 * PHP class to resize and scale images
 */
class ImageResize implements ImageResizeInterface
{
    /**
     * @var int JPEG output quality
     */
    public $quality_jpg = 85;

    /**
     * @var int WEBP output quality
     */
    public $quality_webp = 85;

    /**
     * @var int PNG output quality
     */
    public $quality_png = 6;

    /**
     * @var bool
     */
    public $quality_truecolor = true;

    /**
     * @var bool
     */
    public $gamma_correction = false;

    /**
     * @var int
     */
    public $interlace = 1;

    /**
     * @var mixed
     */
    public $source_type;

    protected $source_image;

    protected int $original_w;

    protected int $original_h;

    protected $dest_x = 0;

    protected $dest_y = 0;

    protected $source_x;

    protected $source_y;

    protected $dest_w;

    protected $dest_h;

    protected $source_w;

    protected $source_h;

    protected $source_info;

    protected $filters = [];

    public static function createFromString(string $image_data):ImageResize
    {
        if (empty( $image_data )) {
            throw new ImageResizeException( __CLASS__ . ' ERROR: image_data must not be empty.' );
        }

        return new self( 'data://application/octet-stream;base64,'.base64_encode( $image_data ) );
    }

    public function __construct($filename)
    {
        if (empty($filename)) {
            throw new ImageResizeException(self::class . " ERROR: No filename given");
        }

        if (substr( $filename, 0, 5 ) !== 'data:' && !is_file( $filename )) {
            throw new ImageResizeException( self::class . " ERROR: Not a file or valid datastream" );
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (false === $finfo) {
            throw new ImageResizeException(self::class . " ERROR: Can't retrieve file info.");
        }

        $checkWEBP = false;

        if (strstr( finfo_file( $finfo, $filename ), 'image/webp' ) !== false) {
            $checkWEBP = true;
            $this->source_type = IMAGETYPE_WEBP;
        }

        if (!$image_info = getimagesize( $filename, $this->source_info )) {
            $image_info = getimagesize( $filename );
        }

        if (!$checkWEBP) {
            if (!$image_info) {
                throw new ImageResizeException( self::class . ' ERROR: Could not read file' );
            }

            $this->original_w = $image_info[ 0 ];
            $this->original_h = $image_info[ 1 ];
            $this->source_type = $image_info[ 2 ];
        }

        switch ($this->source_type) {
            case IMAGETYPE_GIF: {
                $this->source_image = imagecreatefromgif( $filename );
                break;
            }
            case IMAGETYPE_JPEG: {
                $this->source_image = $this->imageCreateJpegfromExif( $filename );
                break;
            }
            case IMAGETYPE_PNG: {
                $this->source_image = imagecreatefrompng( $filename );
                break;
            }
            case IMAGETYPE_WEBP: {
                $this->source_image = imagecreatefromwebp( $filename );
                break;
            }
            case IMAGETYPE_BMP: {
                $this->source_image = imagecreatefrombmp( $filename );
                break;
            }
            default: {
                throw new ImageResizeException( self::class . ' ERROR: Unsupported image type' );
            }
        }

        if (!$this->source_image) {
            throw new ImageResizeException( self::class . ' ERROR: Could not load image' );
        }

        // set new width and height for image, maybe it has changed
        $this->original_w = imagesx( $this->source_image );
        $this->original_h = imagesy( $this->source_image );

        return $this->resize( $this->getSourceWidth(), $this->getSourceHeight() );
    }

    public function addFilter(callable $filter): ImageResize
    {
        $this->filters[] = $filter;
        return $this;
    }

    public function imageCreateJpegfromExif($filename)
    {
        $img = imagecreatefromjpeg( $filename );
        if (false === $img) {
            throw new ImageResizeException(self::class . ' ERROR: Loading jpeg failed');
        }

        if (!function_exists( 'exif_read_data' ) || !isset( $this->source_info[ 'APP1' ] ) || strpos( $this->source_info[ 'APP1' ], 'Exif' ) !== 0) {
            return $img;
        }

        try {
            $exif = @exif_read_data( $filename );
        } catch (Exception $exception) {
            $exif = null;
        }

        if (!$exif || !isset( $exif[ 'Orientation' ] )) {
            return $img;
        }

        $orientation = $exif[ 'Orientation' ];

        if ($orientation === 6 || $orientation === 5) {
            $img = imagerotate( $img, 270, 0 );
        } elseif ($orientation === 3 || $orientation === 4) {
            $img = imagerotate( $img, 180, 0 );
        } elseif ($orientation === 8 || $orientation === 7) {
            $img = imagerotate( $img, 90, 0 );
        }

        if (false === $img) {
            throw new ImageResizeException(self::class . " ERROR: Rotating image failed");
        }

        if ($orientation === 5 || $orientation === 4 || $orientation === 7) {
            if (function_exists( 'imageflip' )) {
                if (!imageflip( $img, IMG_FLIP_HORIZONTAL )) {
                    throw new ImageResizeException(self::class . ' ERROR: Flipping image failed.');
                }

            } else {
                $this->imageFlip( $img, IMG_FLIP_HORIZONTAL );
            }
        }

        return $img;
    }

    public function save($filename, $image_type = null, $quality = null, $permissions = null, $exact_size = false): self
    {
        $image_type = $image_type ?: $this->source_type;
        $quality = is_numeric( $quality ) ? (int)abs( $quality ) : null;
        $source_image = $this->source_image;

        if (!empty($exact_size) && is_array($exact_size)) {
            $_width = $exact_size[0];
            $_height = $exact_size[1];
        } else {
            $_width = $this->getDestWidth();
            $_height = $this->getDestHeight();
        }

        // Prepare image pattern for conversion

        switch ($image_type) {
            case IMAGETYPE_GIF: {
                $dest_image = imagecreatetruecolor( $_width, $_height );
                if (false === $dest_image) {
                    throw new ImageResizeException(self::class . ' Error creating image/gif resource');
                }

                $transparent_color = imagecolorallocatealpha( $dest_image, 255, 255, 255, 1 );
                if (false === $transparent_color) {
                    throw new ImageResizeException(self::class . ' Error creating background alpha color.');
                }

                $transparent_color = imagecolortransparent( $dest_image, $transparent_color );
                if (-1 === $transparent_color) {
                    throw new ImageResizeException(self::class . ' Error defining color as transparent.');
                }

                if (!imagefill( $dest_image, 0, 0, $transparent_color )) {
                    throw new ImageResizeException(self::class . ' Error: filling image with background alpha color failed.');
                }

                //@todo: ???  https://stackoverflow.com/a/11920133/5127037
                if (!imagesavealpha( $dest_image, true )) {
                    throw new ImageResizeException(self::class . ' Error: setting the flag to save full alpha channel information.');
                }

                break;
            }

            case IMAGETYPE_JPEG: {
                $dest_image = imagecreatetruecolor( $_width, $_height );
                if (false === $dest_image) {
                    throw new ImageResizeException(self::class . ' Error creating image/jpeg resource');
                }

                $transparent_color = imagecolorallocate( $dest_image, 255, 255, 255 );
                if (false === $transparent_color) {
                    throw new ImageResizeException(self::class . ' Error creating background alpha color');
                }

                if (!imagefilledrectangle( $dest_image, 0, 0, $_width, $_height, $transparent_color )) {
                    throw new ImageResizeException(self::class . ' Error: filling image with background color failed');
                }

                break;
            }

            case IMAGETYPE_WEBP: {
                $dest_image = imagecreatetruecolor( $_width, $_height );
                if (false === $dest_image) {
                    throw new ImageResizeException(self::class . ' Error creating image/webp resource');
                }

                $transparent_color = imagecolorallocate( $dest_image, 255, 255, 255 );
                if (false === $transparent_color) {
                    throw new ImageResizeException(self::class . ' Error creating background alpha color');
                }

                if (!imagefilledrectangle( $dest_image, 0, 0, $_width, $_height, $transparent_color )) {
                    throw new ImageResizeException(self::class . ' Error: filling image with background color failed');
                }

                if (!imagealphablending( $dest_image, false )) {
                    throw new ImageResizeException(self::class . ' Error setting blending mode for webp image');
                }

                if (!imagesavealpha( $dest_image, true )) {
                    throw new ImageResizeException(self::class . ' Error setting SAVE ALPHA flag');
                }

                break;
            }

            case IMAGETYPE_PNG: {
                if (!$this->quality_truecolor && !imageistruecolor( $source_image )) {
                    $dest_image = imagecreate( $_width, $_height );
                } else {
                    $dest_image = imagecreatetruecolor( $_width, $_height );
                }

                if (false === $dest_image) {
                    throw new ImageResizeException(self::class . ' Error creating image/png resource');
                }

                if (!imagealphablending( $dest_image, false )) {
                    throw new ImageResizeException(self::class . ' Error setting blending mode for webp image');
                }

                if (!imagesavealpha( $dest_image, true )) {
                    throw new ImageResizeException(self::class . ' Error setting SAVE ALPHA flag');
                }

                $transparent_color = imagecolorallocatealpha( $dest_image, 255, 255, 255, 127 );
                if (false === $transparent_color) {
                    throw new ImageResizeException(self::class . ' Error creating background alpha color');
                }

                $transparent_color = imagecolortransparent( $dest_image, $transparent_color );
                if (-1 === $transparent_color) {
                    throw new ImageResizeException(self::class . ' Error defining color as transparent');
                }

                if (!imagefill( $dest_image, 0, 0, $transparent_color )) {
                    throw new ImageResizeException(self::class . ' Error: filling image with background alpha color failed');
                }

                break;
            }
        }

        if (false === imageinterlace( $dest_image, $this->interlace )) {
            throw new ImageResizeException(self::class . ' Error setting interlace flag');
        }

        if (!empty( $exact_size ) && is_array( $exact_size )) {
            if ($this->getSourceHeight() < $this->getSourceWidth()) {
                $this->dest_x = 0;
                $this->dest_y = ($exact_size[ 1 ] - $this->getDestHeight()) / 2;
            }

            if ($this->getSourceHeight() > $this->getSourceWidth()) {
                $this->dest_x = ($exact_size[ 0 ] - $this->getDestWidth()) / 2;
                $this->dest_y = 0;
            }
        }

        if ($this->gamma_correction && !imagegammacorrect( $source_image, 2.2, 1.0 )) {
            throw new ImageResizeException(self::class . ' Error image gamma correction (2.2 -> 1.0)');
        }

        if (!imagecopyresampled(
            $dest_image,
            $source_image,
            $this->dest_x,
            $this->dest_y,
            $this->source_x,
            $this->source_y,
            $this->getDestWidth(),
            $this->getDestHeight(),
            $this->source_w,
            $this->source_h
        )) {
            throw new ImageResizeException(self::class . 'ERROR: Resample image failed');
        }

        if ($this->gamma_correction) {
            if (!imagegammacorrect( $dest_image, 1.0, 2.2 )) {
                throw new ImageResizeException(self::class . ' ERROR: Correction image gamma failed (1.0 -> 2.2)');
            }

            if (!imagegammacorrect( $source_image, 1.0, 2.2 )) {
                throw new ImageResizeException(self::class . ' ERROR: Correction source image gamma failed (1.0 -> 2.2)');
            }
        }

        $this->applyFilter( $dest_image );

        // Save image

        switch ($image_type) {
            case IMAGETYPE_GIF: {
                if (!imagegif( $dest_image, $filename )) {
                    throw new ImageResizeException(self::class . ' ERROR: storing GIF image failed');
                }

                break;
            }
            case IMAGETYPE_JPEG: {
                if ($quality === null || $quality > 100) {
                    $quality = $this->quality_jpg;
                }

                if (!imagejpeg( $dest_image, $filename, $quality )) {
                    throw new ImageResizeException(self::class . ' ERROR: storing JPEG image failed');
                }

                break;
            }
            case IMAGETYPE_WEBP: {
                if ($quality === null) {
                    $quality = $this->quality_webp;
                }

                if (!imagewebp( $dest_image, $filename, $quality )) {
                    throw new ImageResizeException(self::class . ' ERROR: storing WEBP image failed');
                }

                break;
            }
            case IMAGETYPE_PNG: {
                if ($quality === null || $quality > 9) {
                    $quality = $this->quality_png;
                }

                if (!imagepng( $dest_image, $filename, $quality )) {
                    throw new ImageResizeException(self::class . ' ERROR: storing PNG image failed');
                }

                break;
            }
        }

        if ($permissions && !chmod( $filename, $permissions )) {
            throw new ImageResizeException(self::class . ' ERROR: setting destination file permissions failed');
        }

        if (!imagedestroy( $dest_image )) {
            throw new ImageResizeException(self::class . ' ERROR: cleaning temporary image failed.');
        }

        return $this;
    }

    public function getImageAsString($image_type = null, $quality = null): string
    {
        $temporary_filename = tempnam( sys_get_temp_dir(), '' );
        if (false === $temporary_filename) {
            throw new ImageResizeException(self::class . 'ERROR: generating temporary filename failed');
        }

        $this->save( $temporary_filename, $image_type, $quality );

        $data = file_get_contents( $temporary_filename );
        if (false === $data) {
            throw new ImageResizeException(self::class . ' ERROR: loading temporary file failed');
        }

        if (!unlink( $temporary_filename )) {
            throw new ImageResizeException(self::class . ' ERROR: unlinking temporary file failed');
        }

        return $data;
    }

    public function __toString(): string
    {
        return $this->getImageAsString();
    }

    public function output($image_type = null, $quality = null): void
    {
        $image_type = $image_type ?: $this->source_type;

        header( 'Content-Type: ' . image_type_to_mime_type( $image_type ) );

        $this->save( null, $image_type, $quality );
    }

    public function resizeToShortSide($max_short, $allow_enlarge = false): self
    {
        if ($this->getSourceHeight() < $this->getSourceWidth()) {
            $ratio = $max_short / $this->getSourceHeight();
            $long = $this->getSourceWidth() * $ratio;

            $this->resize($long, $max_short, $allow_enlarge);
        } else {
            $ratio = $max_short / $this->getSourceWidth();
            $long = $this->getSourceHeight() * $ratio;

            $this->resize($max_short, $long, $allow_enlarge);
        }

        return $this;
    }

    public function resizeToLongSide($max_long, $allow_enlarge = false): self
    {
        if ($this->getSourceHeight() > $this->getSourceWidth()) {
            $ratio = $max_long / $this->getSourceHeight();
            $short = $this->getSourceWidth() * $ratio;

            $this->resize( $short, $max_long, $allow_enlarge );
        } else {
            $ratio = $max_long / $this->getSourceWidth();
            $short = $this->getSourceHeight() * $ratio;

            $this->resize( $max_long, $short, $allow_enlarge );
        }

        return $this;
    }

    public function resizeToHeight($height, $allow_enlarge = false): self
    {
        $ratio = $height / $this->getSourceHeight();
        $width = $this->getSourceWidth() * $ratio;

        $this->resize( $width, $height, $allow_enlarge );

        return $this;
    }

    public function resizeToWidth($width, $allow_enlarge = false): self
    {
        $ratio = $width / $this->getSourceWidth();
        $height = $this->getSourceHeight() * $ratio;

        $this->resize( $width, $height, $allow_enlarge );

        return $this;
    }

    public function resizeToBestFit($max_width, $max_height, $allow_enlarge = false)
    {
        if ($this->getSourceWidth() <= $max_width && $this->getSourceHeight() <= $max_height && !$allow_enlarge) {
            return $this;
        }

        $ratio = $this->getSourceHeight() / $this->getSourceWidth();
        $width = $max_width;
        $height = $width * $ratio;

        if ($height > $max_height) {
            $height = $max_height;
            $width = (int)round( $height / $ratio );
        }

        return $this->resize( $width, $height, $allow_enlarge );
    }

    public function scale($scale): self
    {
        if ($scale === 100) {
            return $this;
        }

        $width = $this->getSourceWidth() * $scale / 100;
        $height = $this->getSourceHeight() * $scale / 100;

        $this->resize( $width, $height, true );

        return $this;
    }

    public function resize($width, $height, $allow_enlarge = false): self
    {
        // if the user hasn't explicitly allowed enlarging,
        // but either of the dimensions are larger then the original,
        // then just use original dimensions - this logic may need rethinking
        if (!$allow_enlarge && ($width > $this->getSourceWidth() || $height > $this->getSourceHeight())) {
            $width = $this->getSourceWidth();
            $height = $this->getSourceHeight();
        }

        $this->source_x = 0;
        $this->source_y = 0;

        $this->dest_w = $width;
        $this->dest_h = $height;

        $this->source_w = $this->getSourceWidth();
        $this->source_h = $this->getSourceHeight();

        return $this;
    }

    public function crop($width, $height, $allow_enlarge = false, $position = self::CROPCENTER): self
    {
        if (!$allow_enlarge) {
            // this logic is slightly different to resize(),
            // it will only reset dimensions to the original
            // if that particular dimenstion is larger

            if ($width > $this->getSourceWidth()) {
                $width = $this->getSourceWidth();
            }

            if ($height > $this->getSourceHeight()) {
                $height = $this->getSourceHeight();
            }
        }

        $ratio_source = $this->getSourceWidth() / $this->getSourceHeight();
        $ratio_dest = $width / $height;

        if ($ratio_dest < $ratio_source) {
            $this->resizeToHeight( $height, $allow_enlarge );

            $excess_width = ($this->getDestWidth() - $width) / $this->getDestWidth() * $this->getSourceWidth();

            $this->source_w = $this->getSourceWidth() - $excess_width;
            $this->source_x = $this->getCropPosition( $excess_width, $position );

            $this->dest_w = $width;
        } else {
            $this->resizeToWidth( $width, $allow_enlarge );

            $excess_height = ($this->getDestHeight() - $height) / $this->getDestHeight() * $this->getSourceHeight();

            $this->source_h = $this->getSourceHeight() - $excess_height;
            $this->source_y = $this->getCropPosition( $excess_height, $position );

            $this->dest_h = $height;
        }

        return $this;
    }

    public function freecrop($width, $height, $x = false, $y = false)
    {
        if ($x === false || $y === false) {
            return $this->crop( $width, $height );
        }

        $this->source_x = $x;
        $this->source_y = $y;
        $this->source_w = $width > $this->getSourceWidth() - $x ? $this->getSourceWidth() - $x : $width;

        $this->source_h = $height > $this->getSourceHeight() - $y ? $this->getSourceHeight() - $y : $height;

        $this->dest_w = $width;
        $this->dest_h = $height;

        return $this;
    }

    public function getSourceWidth()
    {
        return $this->original_w;
    }

    public function getSourceHeight()
    {
        return $this->original_h;
    }

    public function getDestWidth()
    {
        return $this->dest_w;
    }

    public function getDestHeight()
    {
        return $this->dest_h;
    }

    /**
     * Apply filters.
     *
     * @param $image - resource an image resource identifier
     * @param $filterType - filter type and default value is IMG_FILTER_NEGATE
     */
    protected function applyFilter($image, $filterType = IMG_FILTER_NEGATE)
    {
        foreach ($this->filters as $function) {
            $function( $image, $filterType );
        }
    }

    /**
     * Gets crop position (X or Y) according to the given position
     *
     * @param integer $expectedSize
     * @param integer $position
     * @return float|integer
     */
    protected function getCropPosition($expectedSize, $position = self::CROPCENTER)
    {
        $size = 0;
        switch ($position) {
            case self::CROPBOTTOM:
            case self::CROPRIGHT:
                $size = $expectedSize;
                break;
            case self::CROPCENTER:
            case self::CROPCENTRE:
                $size = $expectedSize / 2;
                break;
            case self::CROPTOPCENTER:
                $size = $expectedSize / 4;
                break;
        }

        return $size;
    }

    public function imageFlip($image, $mode)
    {
        switch ($mode) {
            case self::IMG_FLIP_HORIZONTAL:
            {
                $max_x = imagesx( $image ) - 1;
                $half_x = $max_x / 2;
                $sy = imagesy( $image );
                $temp_image = imageistruecolor( $image ) ? imagecreatetruecolor( 1, $sy ) : imagecreate( 1, $sy );
                for ($x = 0; $x < $half_x; ++$x) {
                    imagecopy( $temp_image, $image, 0, 0, $x, 0, 1, $sy );
                    imagecopy( $image, $image, $x, 0, $max_x - $x, 0, 1, $sy );
                    imagecopy( $image, $temp_image, $max_x - $x, 0, 0, 0, 1, $sy );
                }

                break;
            }
            case self::IMG_FLIP_VERTICAL:
            {
                $sx = imagesx( $image );
                $max_y = imagesy( $image ) - 1;
                $half_y = $max_y / 2;
                $temp_image = imageistruecolor( $image ) ? imagecreatetruecolor( $sx, 1 ) : imagecreate( $sx, 1 );
                for ($y = 0; $y < $half_y; ++$y) {
                    imagecopy( $temp_image, $image, 0, 0, 0, $y, $sx, 1 );
                    imagecopy( $image, $image, 0, $y, 0, $max_y - $y, $sx, 1 );
                    imagecopy( $image, $temp_image, 0, $max_y - $y, 0, 0, $sx, 1 );
                }

                break;
            }
            case self::IMG_FLIP_BOTH:
            {
                $sx = imagesx( $image );
                $sy = imagesy( $image );
                $temp_image = imagerotate( $image, 180, 0 );
                imagecopy( $image, $temp_image, 0, 0, 0, 0, $sx, $sy );
                break;
            }
            default:
                return null;
        }

        imagedestroy( $temp_image );
        return null;
    }

    public function gamma($enable = true): self
    {
        $this->gamma_correction = $enable;
        return $this;
    }

    public function setQualityJPEG($quality): self
    {
        $this->quality_jpg = $quality ?: $this->quality_jpg;
        return $this;
    }

    public function setQualityPNG($quality): self
    {
        $this->quality_png = $quality ?: $this->quality_png;
        return $this;
    }

    public function setQualityWebp($quality): self
    {
        $this->quality_webp = $quality ?: $this->quality_webp;
        return $this;
    }

}
