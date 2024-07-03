# Rezise image stored in a string

Q: 

I have a PNG stored in a string taken from mysql BLOB. 
I get the following error: Warning: is_file() expects parameter 1 to be a valid path, string given in /var/www/html/signature/ImageResize.php on line 108

A: 

It seems to be a feature because the ImageResize class constructor supports file name, not image binary string at this moment.

```php
$str = file_get_contents(__DIR__ . '/test_in.jpg'); // get data from file or BLOB
$str = 'data://image/jpeg;base64,' . base64_encode( $str ); // also, you will store mime type of your image near BLOB data

$image = new \Gumlet\ImageResize($str);
$image->scale(50)->save('test_out.png', IMAGETYPE_PNG);
```

