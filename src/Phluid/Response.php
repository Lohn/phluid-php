<?php
namespace Phluid;
use React\Http\Response as HttpResponse;
use Evenement\EventEmitter;
use React\Stream\ThroughStream;
use React\Stream\WritableStreamInterface;

class Response extends EventEmitter implements WritableStreamInterface {
  
  private $options = array();

  /** @var ThroughStream $responseBody */
  private $responseBody;

  /** @var string $rawResponseBody */
  private $rawResponseBody;

  /** @var Request  */
  private $request;

  /** @var ResponseHeaders|array $headers  */
  private $headers;
  
  function __construct(Request $request ){
    $this->request = $request;
    $this->responseBody = new ThroughStream();
    $this->rawResponseBody = "";

    $this->responseBody->on("data", function($_data){
    	$this->rawResponseBody .= $_data;
    });

    $this->headers = new ResponseHeaders( 200, 'Success' );
    Utils::forwardEvents( $this, $this->responseBody, array( 'error', 'end', 'drain' ) );
    $date = new \DateTime( 'now' );
    $this->setHeader( 'Date', $date->format( \DateTime::RFC1123 ) );
    
  }
  
  public function __toString(){
    return (string) $this->headers;
  }
  
  public function getOptions(){
    return $this->options;
  }
  
  public function setOptions( array $options ){
    $this->options = array_merge( $this->options, $options );
  }
    
  public function getHeaders(){
    return $this->headers;
  }
  
  public function setHeaders( $headers ){
    foreach ( $headers as $name => $value ) {
      $this->setHeader( $name, $value );
    }
  }
  
  public function getHeader( $key ){
    return $this->headers[$key];
  }
  
  public function setHeader( $key, $value ){
    $this->headers[$key] = $value;
  }
  
  public function removeHeader( $header ){
    unset( $this->headers[$header] );
  }
  
  public function getStatus(){
    return $this->headers->status;
  }
  
  public function setStatus( $status ){
    $this->headers->status = $status;
  }
  
  
  public function sendHeaders( $status_or_headers = 200, $headers = array() ){
    if ( !is_null( $status_or_headers ) and is_int( $status_or_headers ) ) {
      $this->headers->status = $status_or_headers;
    } else if ( !is_null( $status_or_headers ) && is_array( $status_or_headers ) ) {
      $headers = $status_or_headers;
    }
    $this->setHeaders( $headers );
    $this->emit( 'headers' );
  }
  
  public function render( $template, $locals = array(), $options = array() ){
    $layout = Utils::array_val( $options, 'layout', $this->options['default_layout'] );
    $status = Utils::array_val( $options, 'status', 200 );
    $content_type = Utils::array_val($options, 'content-type', 'text/html' );
    $locals['request'] = $this->request;
    $view = new View( $template, $layout, $this->options['view_path'] );
    $this->renderText( $view->render( $locals ), $content_type, $status );
  }
  
  public function renderText( $string, $content_type="text/plain", $status = 200 ){
    $this->setHeader( 'Content-Type', $content_type );
    $this->setHeader( 'Content-Length', strlen( (string) $string ) );
    // write the headers and the body
    $this->sendHeaders( $status );
    $this->end( (string) $string );
  }
  
  public function redirectTo( $path, $status = 302 ){
    $this->setHeader( 'location', $path );
    $this->sendHeaders( $status );
    $this->end();
  }
  
  
  /**
   * Iterate throuch each header name/value with a callback
   *
   * @param string $callback that accepts to arguments
   * @return void
   * @author Beau Collins
   */
  public function eachHeader( $callback ){
    foreach( $this->headers->toArray() as $name => $value ){
      $callback( $name, $value );
    }
  }
  
  public function sendFile( $path, $options_or_status = array(), $status = 200 ){
    if ( is_int( $options_or_status )) {
      $status = $options_or_status;
      $options = array();
    } else {
      $options = $options_or_status;
    }
    if ( array_key_exists( 'attachment', $options ) ) {
      $disposition = $options['attachment'];
      if( $disposition === true ){
        $disposition = "attachment;";
      } else {
        $disposition = "attachment; filename=\"$disposition\"";
      }
      $this->setHeader( 'Content-Disposition', $disposition );
    }
    if( is_readable( $path ) ){
      $handle = fopen( $path, 'r' );
      $info = fstat( $handle );
      $last_modified = \DateTime::createFromFormat( 'U', $info['mtime'] );
      $this->setHeader( 'Last-Modified', $last_modified->format( \DateTime::RFC1123 ) );
      
      $this->setHeader( 'Content-Length', (string) filesize( $path ) );
      $this->on( 'end', function() use ( $handle ){
        fclose( $handle );
      });
      
      $readFile = function() use ( $handle ){
        while( $string = fread( $handle, 2048 ) ){
          if ( feof( $handle ) ) {
            $this->end( $string );
            return;
          } else {
            if( !$this->write( $string ) ) return;
          }
        }
      };
      $this->on( 'drain', $readFile );
      $this->sendHeaders( $status );
      $readFile();
    } else {
      $this->sendHeaders( 404 );
    }
  }
  
  public function isWritable(){
    return $this->responseBody->isWritable();
  }
  public function write( $data ){
    return $this->responseBody->write( $data );
  }
  public function end( $data = null ){
  	$this->responseBody->end( $data );
  }
  
  public function close(){
  	$this->responseBody->close();
  }

  /**
   * Creates and returns a React http response from the data that is stored in this Response.
   *
   * @return HttpResponse The generated http response
   */
  public function getHttpResponse()
  {
  	return new HttpResponse(
  		$this->headers->status,
	    $this->headers->toArray(),
	    $this->rawResponseBody,
	    $this->request->getProtocolVersion()
    );
  }
  
  
}
