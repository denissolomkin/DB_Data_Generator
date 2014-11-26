<?php
if (php_sapi_name()!='cli' AND substr(php_sapi_name(),0,3)!='cgi')    session_start();
class CONTROLLER
{
    public $model;
    public $view;

    function __construct()
    {
        $this->view = new VIEW;
        $this->model = new MODEL;
    }

    public function start()
    {

        $settings=getopt("l:d:u:p:t:r:",array("location:","database:","login:","password:","table:","rows:"));
        $connect=$_POST['database']?$_POST['database']: ($settings ? $settings : $_SESSION['db']);
        $table=  $_POST['table']   ?$_POST['table']   : ($settings['table'] ? $settings : null);

        $database=$this->model->connectDB($connect);
        if($table)                 $generate=$this->model->setTable($table);
        if($database['success'])   $tables=$this->model->getTables($database);

        $this->view->loadTpl('top');
        $this->view->loadTpl('form',$database);
        $this->view->loadTpl('tables',$tables);
        $this->view->loadTpl('generate',$generate);
        $this->view->loadTpl('bottom');
    }
}

class MODEL
{
    private $tables;
    private $db;

    /* connect to database */
    public function connectDB($db)
    {
        $this->db= $_SESSION['db']=$db;
        if(!$this->db[location])  $this->db['error']='Please, set location';
        elseif(!$this->db[database])      $this->db['error']='Please, set database';
        elseif(!$this->db[login])     $this->db['error']='Please, set login';
        elseif(!$this->db[password])  $this->db['error']='Please, set password';
        elseif (!@$link = mysql_connect($this->db['location'], $this->db['login'], $this->db['password']))
            $this->db['error']='Not connect db, check location, login and password';
        elseif (!@mysql_select_db($this->db['database'],$link))
            $this->db['error']='Not select db, check database';
        else
            $this->db['success']='Connection is success';
        return $this->db;
    }

    /* select tables from database */
    public function getTables($db)
    {

        $sql= 'select CONCAT(c.TABLE_NAME, " (rows: ",t.TABLE_ROWS,")") `TABLE_NAME`, c.COLUMN_NAME `NAME`, c.COLUMN_DEFAULT `DEFAULT`,c.IS_NULLABLE `NULL`,c.COLUMN_TYPE `TYPE`, c.CHARACTER_MAXIMUM_LENGTH `LENGTH`, c.COLUMN_KEY `KEY`, c.EXTRA from information_schema.columns c join  information_schema.tables t on t.table_name=c.TABLE_NAME where c.table_schema = "'.$db['database'].'" order by c.table_name,c.ordinal_position';
        if ($res=mysql_query($sql)) {
            while ($row = mysql_fetch_assoc($res)) {
                if(php_sapi_name()=='cli' OR substr(php_sapi_name(),0,3)=='cgi')
                    $row=array_filter($row);
                $this->tables[$row['TABLE_NAME']][$row['NAME']]=$row;
                    unset($this->tables[$row['TABLE_NAME']][$row['NAME']]['TABLE_NAME']);
            }
        }
        return $this->tables;
    }


    /* set generate data to table */
    public function setTable($table)
    {
        $sql= 'select * from information_schema.columns where TABLE_NAME="'.$table['table'].'" AND table_schema = "'.$this->db['database'].'"order by table_name,ordinal_position';
        if ($res=mysql_query($sql)) {
            /* select columns from table */
            while ($row = mysql_fetch_assoc($res)) {
                if($row['EXTRA']=='auto_increment' || $row['COLUMN_DEFAULT']) continue;
                $columns[$row['COLUMN_NAME']]=$row;
            }
            /* generate random data for columns*/
            do{
                $i++;
                foreach($columns as $column)
                    $generate[$i][$column['COLUMN_NAME']]=$this->setColumn($column);
                $query[]='('.implode(',',$generate[$i]).')';
            } while ($i<$table['rows']);
        }

        $sql= ("INSERT INTO `".$table['table']."` (`".(implode("`,`",array_keys($generate[1])))."`) VALUES".(implode(",",$query)));
        mysql_query($sql);
        return $generate;
    }


    /* set generate data for column */
    private function setColumn($col=array())
    {

        switch ($col['DATA_TYPE']) {
            case 'tinyint':
            case 'smallint':
            case 'mediumint':
            case 'bigint':
            case 'int':
                return $this->genInt($col['COLUMN_TYPE']);
                break;
            case 'float':
            case 'double':
            case 'real':
                return $this->genFloat($col['COLUMN_TYPE']);
                break;
            case 'numeric':
            case 'decimal':
                return $this->genDec($col['COLUMN_TYPE']);
                break;
            case 'enum':
            case 'set':
                return $this->genSet($col['COLUMN_TYPE']);
                break;
            case 'char':
            case 'varchar':
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
                return $this->genChar($col['CHARACTER_MAXIMUM_LENGTH']);
                break;
            case 'timestamp':
            case 'datetime':
                return $this->genDate('Y-m-d h:i:s');
                break;
            case 'time':
                return $this->genDate('h:i:s');
                break;
            case 'date':
                return $this->genDate('Y-m-d');
                break;
            case 'year':
                return ($col['COLUMN_TYPE']=='year(4)' ? $this->genDate('Y') : $this->genDate('y'));
                break;
            default:
                return '0';
        }
    }

    /* CHAR type of column */
    private function genChar($len=1)
    {
        if($len>256) $len=rand(32,($len>1024?512:$len));
        $values="0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ     ";
        for($i=0;$i<$len;$i++)
            $ret.=$values[rand(0,66)];
        return '\''.$ret.'\'';
    }

    /* INT type of column */
    private function genInt($type)
    {
        $int=array('tinyint'=>1,'smallint'=>2,'mediumint'=>3,'int'=>4,'bigint'=>8);
        $max=(int)pow(256,$int[substr($type,0,(strpos($type,'(')))]);
        if($max==0) $max=PHP_INT_MAX;
        if(strstr($type,'unsigned'))
        {
            $min=0; $max=-1;
        }
        else
        {
            $min=$max/-2;$max=$max/2-1;
        }
        return mt_rand($min,$max);
    }

    /* SET type of column */
    private function genSet($type)
    {
        $set=explode(',',substr($type,strpos($type,'(')+1,(strpos($type,')')-1-strpos($type,'('))));
        return $set[rand(0,count($set)-1)];
    }
    /* DEC type of column */
    private function genDec($type)
    {
        $minus=array(1,-1);
        $len=explode(',',substr($type,strpos($type,'(')+1,(strpos($type,')')-1-strpos($type,'('))));
        /* if has scale, when -1 of precision */
        if($len[1]) $len[0]--;
        /* if has unsigned, when +1 of precision, else shuffle $minus*/
        strstr($type,'unsigned') ? $len[0]++ : shuffle ($minus);
        $max=pow(10,$len[0])-1;
        return $minus[0]*rand(0,$max)/(pow(10,$len[1]));
    }

    /* FLOAT type of column */
    private function genFloat($type)
    {
        $minus=array(1,-1);
        strstr($type,'unsigned') ? : shuffle ($minus);
        return $minus[0]*rand()/100;
    }


    /* DATE type of column */
    private function genDate($format)
    {   $start = mktime(0,0,0,1970,1,1);
        $end  = time();
        return '\''.date($format,rand($start,$end)).'\'';
    }
}


class VIEW
{
    public function loadTpl($tpl,$data=true)
    {
        if($data==false) {return;}
        if(php_sapi_name()=='cli'){
            if(is_array($data))
            {echo strtoupper($tpl).":\n"; print_r($data);}
        }
        else
            include('tpl/'.$tpl.".html");
    }
}

$control=new CONTROLLER;
$control->start();
?>