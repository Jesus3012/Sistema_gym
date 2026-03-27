<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'gym_sistema');

class Database {
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;
    
    private $conn;
    
    public function __construct() {
        $this->connect();
    }
    
    private function connect() {
        try {
            $this->conn = new mysqli($this->host, $this->user, $this->pass, $this->dbname);
            
            if ($this->conn->connect_error) {
                throw new Exception("Error de conexión: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset("utf8");
        } catch (Exception $e) {
            die($e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
}
?>