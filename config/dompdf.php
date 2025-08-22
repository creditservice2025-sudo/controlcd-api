<?php

return array(

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    |
    | Set some default values. It is possible to add all defines that can be set
    | in dompdf_config.inc.php. You can also override the entire config file.
    |
    */
    'show_warnings' => false,   // Throw an Exception on warnings from dompdf
    'orientation' => 'portrait',
    'defines' => array(
        /**
         * The location of the DOMPDF font directory
         *
         * The location of the directory where DOMPDF will store fonts and font metrics
         * Note: This directory must exist and be writable by the webserver process.
         * *Please note the trailing slash.*
         *
         * Notes regarding fonts:
         * Additional .afm font metrics can be added by executing load_font.php from command line.
         *
         * Only the original "Base 14" fonts are present by default. They are courier, courier-bold, courier-oblique,
         * courier-boldoblique, helvetica, helvetica-bold, helvetica-oblique, helvetica-boldoblique, times-roman,
         * times-bold, times-italic, times-bolditalic, symbol, zapfdingbats.
         */
        "font_dir" => storage_path('fonts/'), // advised by dompdf (https://github.com/dompdf/dompdf/wiki/FAQ#where-are-the-fonts-installed)
        "font_cache" => storage_path('fonts/'),
        /**
         * The location of the DOMPDF font directory
         *
         * This item determines the location of the directory where DOMPDF will
         * store the temporary files used during the PDF generation. This directory
         * must be writable by the webserver process. The temporary files are only
         * required during the generation of the PDF, and can be removed after the
         * PDF is generated.
         *
         * Note: This directory must exist and be writable by the webserver process.
         * *Please note the trailing slash.*
         */
        "temp_dir" => sys_get_temp_dir(),
        /**
         * The location of the DOMPDF font directory
         *
         * This item determines the location of the directory where DOMPDF will
         * look for image files that are specified by full URL. This directory
         * must be writable by the webserver process.
         *
         * Note: This directory must exist and be writable by the webserver process.
         * *Please note the trailing slash.*
         */
        "chroot" => realpath(base_path()),
        /**
         * Protocol whitelist
         *
         * Protocols and PHP wrappers allowed in URIs, and the validation rules
         * that determine if a resouce may be loaded. Full support is not guaranteed
         * for the protocols/wrappers specified by this array.
         */
        "allowed_protocols" => array("file://", "http://", "https://"),
        /**
         * Operational artifact
         *
         * This item determines the location of the directory where DOMPDF will
         * store the temporary files used during the PDF generation. This directory
         * must be writable by the webserver process. The temporary files are only
         * required during the generation of the PDF, and can be removed after the
         * PDF is generated.
         *
         * Note: This directory must exist and be writable by the webserver process.
         * *Please note the trailing slash.*
         */
        "log_output_file" => storage_path('logs/dompdf.html'),
    ),

);