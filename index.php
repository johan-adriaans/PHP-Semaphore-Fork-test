<?php

/*
 * Proof of concept php script that calls and forks itself to execute a heavy task and uses a unix socket connection to poll for status updates.
 * 
 * @author Johan Adriaans <johan@izi-services.nl>
 */

$cache_folder = dirname(__FILE__) . "/cache";
$socket_file = $cache_folder ."/socket.sock";
$socket_url = "unix://" . $socket_file;

if ( !is_dir( $cache_folder ) ) mkdir( $cache_folder, 0777 );

if ( php_sapi_name() === 'cli' ) {
  /**
   * We are in CLI mode!
   */
  $i = 0;
  switch( $pid = pcntl_fork() ) {
    case -1:
      die( "Fork failed" );
      break;
    case 0:
      /**
       * This is the forked child socket server that communicates with the parent using a data file
       */
      $socket = stream_socket_server( $socket_url, $errno, $errstr );

      $shm_key = ftok( __FILE__, 't' );
      $shm_id = shmop_open( $shm_key, "a", 0600, 100 );

      if ( !$socket ) {
        print "$errstr ($errno)<br />\n";
      } else {
        while ( 1 ) {
          $conn = stream_socket_accept( $socket );
          $sock_data = fread( $conn, 1024 );
          if ( strlen( $sock_data ) ) {
            switch( $sock_data ) {
              case "status":
                fwrite( $conn, "Current progress: " . shmop_read( $shm_id , 0 , 100 ) . "/10\n" );
                fclose( $conn );
              break;
              default:
                fwrite( $conn, "You have sent :[".$sock_data."]\n" );
                fclose( $conn );
                break;
            }
          }
        }
      }
      break;
    default:
      /**
       * This is the parent process that executes the heavy task and writes its progress in a data file for the socket server child to pick up
       */
      $shm_key = ftok( __FILE__, 't' );
      $shm_id = shmop_open( $shm_key, "c", 0600, 100 );
      while( 1 ) {
        shmop_write( $shm_id, $i++, 0 );
        sleep( 1 );
        if ( $i >= 10 ) {
          posix_kill( $pid, SIGKILL );
          pcntl_waitpid( $pid );
          shmop_delete( $shm_id );
          shmop_close( $shm_id );
          unlink( $socket_file );
          exit();
        }
      }
      break;
  }
} else {
  /**
   * Apache mode! This part goes to the browser. It looks for a socket, if socket is not found it launches this file in CLI mode so a server is created.
   * If a server is indeed found, it will poll for a status update
   */
  if ( file_exists( $socket_file ) ) {
    $fp = stream_socket_client( $socket_url, $errno, $errstr );
  }

  print "<h1>Control, monitor and launch long running scripts tech demo</h1>";
  print "<h3>Live demo</h3>";

  if ( !$fp ) {
    print "No socket server found, starting one....<br />\n";
    exec( 'nohup /usr/bin/php '. __FILE__ .' > /dev/null 2>&1 & print $!' );
    usleep( 200000 );
    $fp = stream_socket_client($socket_url, $errno, $errstr );
    if ( !$fp ) die( "Could not start server.." );
  }

  print "Socket server found! Getting status update from running process<br /><br />\n";
  
  fwrite( $fp, "status" );
  while ( !feof( $fp ) ) {
    print fgets( $fp, 1024 );
  }
  fclose( $fp );
  print "<br /><br />Refresh browser to get next status update :)";

  print "<h1>Source</h1>";
  show_source( __FILE__ );
}

?>
