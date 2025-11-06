<?php
header("Content-Type: text/plain");
foreach (["file_uploads","upload_max_filesize","post_max_size","max_file_uploads","upload_tmp_dir"] as $k) {
  printf("%s = %s\n", $k, ini_get($k));
}
