<?php



namespace Solenoid\Fluid;



use \Solenoid\HTTP\Request as HttpRequest;
use \Solenoid\HTTP\Response;
use \Solenoid\HTTP\Status;
use \Solenoid\HTTP\Server;

use \Solenoid\MySQL\Model;
use \Solenoid\MySQL\ConnectionStore;



class Engine
{
    const E_INVALID_KEY = 'E_INVALID_KEY';
    const E_INSERT      = 'E_INSERT';

    const S_OK          = 'S_OK';
    const S_RESULT      = 'S_RESULT';



    private array $events    = [];

    private array $whitelist = [];
    private array $blacklist = [];



    public string $conn_id;
    public string $database;
    public string $table;
    public string $action;

    public array  $policy    = [];

    public ?array $input;

    public array  $entities;
    public bool   $debug;

    public Model  $model;



    # Returns [self]
    private function iterate_insert (array $records)
    {
        foreach ( $records as $record )
        {// Processing each entry
            // (Setting the value)
            $values = [];

            foreach ( $record as $k => $v )
            {// Processing each entry
                if ( strpos( $k, ':' ) !== false )
                {// (Column represents a new record to insert)
                    // (Getting the values)
                    [ $alias, $table ] = explode( ':', $k, 2 );

                    // (Calling the function)
                    $this->iterate_insert( $v );
                }
                else
                {// (Column does not represent a new record to insert)
                    // (Getting the value)
                    $values[ $k ] = $v;
                }
            }



            if ( !$this->model->insert( [ $values ], in_array( 'insert_ignore', $this->policy ) ) )
            {// (Unable to insert the records)
                // (Triggering the event)
                $this->trigger( 'error', [ 'type' => self::E_INSERT, 'message' => "Unable to insert the records [$this->conn_id/$this->database/$this->table]", 'error' => $this->model->connection->get_error_text() ] );



                // Returning the value
                return $this;
            }
        }
    }



    # Returns [self]
    public function __construct (array $entities = [], bool $debug = false)
    {
        // (Getting the value)
        $request = HttpRequest::fetch();



        // (Getting the values)
        $this->conn_id   = $request->headers['Fluid-Conn-Id'];
        $this->database  = $request->headers['Fluid-Database'];
        $this->table     = $request->headers['Fluid-Table'];
        $this->action    = $request->headers['Fluid-Action'];

        $this->policy    = explode( ';', $request->headers['Fluid-Policy'] );



        // (Getting the value)
        $this->input = json_decode( $request->body, true );



        // (Getting the value)
        $this->entities = $entities;



        // (Getting the value)
        $this->debug = $debug;

        if ( $this->debug )
        {// Value is true
            // (Listening for the events)
            $this->on( 'error', function ($event) { return Server::send( new Response( new Status(500), [], $event ) ); } );
            $this->on( 'result', function ($event) { return Server::send( new Response( new Status(200), [], $event ) ); } );
        }
    }



    # Returns [self]
    public function on (string $event_type, callable $callback)
    {
        // (Getting the value)
        $this->events[ $event_type ][] = $callback;



        // Returning the value
        return $this;
    }

    # Returns [self]
    public function trigger (string $event_type, mixed $data)
    {
        foreach ( $this->events[ $event_type ] as $callback )
        {// Processing each entry
            // (Calling the function)
            $callback( $data );
        }



        // Returning the value
        return $this;
    }



    # Returns [self]
    public function allow (string $key, string $value)
    {
        // (Getting the value)
        $this->whitelist[ $key ][] = $value;



        // Returning the value
        return $this;
    }

    # Returns [self]
    public function deny (string $key, string $value)
    {
        // (Getting the value)
        $this->blacklist[ $key ][] = $value;



        // Returning the value
        return $this;
    }



    # Returns [self]
    public function run ()
    {
        // (Setting the value)
        $keys = [ 'conn_id', 'database', 'table', 'action' ];



        foreach ( $keys as $key )
        {// Processing each entry
            // (Getting the value)
            $value = $this->{ $key };

            if ( in_array( $value, $this->blacklist[ $key ] ) )
            {// Match OK
                // (Triggering the event)
                $this->trigger( 'error', [ 'type' => self::E_INVALID_KEY, 'message' => "Value '$value' for key '$key' is denied" ] );



                // Returning the value
                return $this;
            }
        }



        // (Setting the value)
        $whitelist_pass = [];

        foreach ( $keys as $key )
        {// Processing each entry
            // (Appending the value)
            $whitelist_pass[] = in_array( $this->{ $key }, $this->whitelist[ $key ] );
        }

        foreach ( $whitelist_pass as $result )
        {// Processing each entry
            if ( !$result )
            {// Match failed
                // (Setting the value)
                $whitelist_pass = false;

                // Breaking the iteration
                break;
            }
        }



        if ( $whitelist_pass === false )
        {// (Whitelist check failed)
            // (Triggering the event)
            $this->trigger( 'error', [ 'type' => self::E_INVALID_KEY, 'message' => "Value for key is denied" ] );



            // Returning the value
            return $this;
        }



        // (Creating a Model)
        $this->model = new Model( ConnectionStore::get( $this->conn_id ), $this->database, $this->table );

        switch ( $this->action )
        {
            case 'insert':
                /*

                if ( !$model->insert( $this->input, in_array( 'insert_ignore', $this->policy ) ) )
                {// (Unable to insert the records)
                    // (Triggering the event)
                    $this->trigger( 'error', [ 'type' => self::E_INSERT, 'message' => "Unable to insert the records [$this->conn_id/$this->database/$this->table]", 'error' => $model->connection->get_error_text() ] );



                    // Returning the value
                    return $this;
                }

                */



                // (Iterating the input)
                $this->iterate_insert( $this->input );



                // (Triggering the event)
                $this->trigger( 'ok', [ 'type' => self::S_OK, 'message' => "Records have been inserted" ] );



                if ( in_array( 'fetch_ids', $this->policy ) )
                {// Match OK
                    // (Triggering the event)
                    $this->trigger( 'result', [ 'type' => self::S_RESULT, 'output' => $this->model->fetch_ids() ] );
                }
            break;

            case 'update':
                // ahcid
            break;
        }



        // Returning the value
        return $this;
    }
}



?>