<?php

namespace AppBundle\Services;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Doctrine\ORM\EntityManager;

use AppBundle\Entity\Product;
use AppBundle\Entity\Category;
use AppBundle\Entity\Feature;
use AppBundle\Entity\UnitMeasure;
use AppBundle\Entity\ProductWarehouse;
use AppBundle\Entity\ProductLang;
use AppBundle\Entity\Warehouse;



/**
 * Description of Syncronize
 *
 * @author catalin
 */
class Syncronize {

    protected $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }
    
    /*
     * o folosim pentru citire linii din fisiere mari
     * vezi detalii aici:
     * http://stackoverflow.com/questions/15025875/what-is-the-best-way-in-php-to-read-last-lines-from-a-file
     * si aici
     * https://gist.github.com/lorenzos/1711e81a9162320fde20
     * 
     * returneaza un string \n pentru separare linii
     * 
     */
    function tailCustom($filepath, $lines = 1, $adaptive = true) {
            // Open file
            $f = @fopen($filepath, "rb");
            if ($f === false) return false;
            // Sets buffer size
            if (!$adaptive) $buffer = 4096;
            else $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
            // Jump to last character
            fseek($f, -1, SEEK_END);
            // Read it and adjust line number if necessary
            // (Otherwise the result would be wrong if file doesn't end with a blank line)
            if (fread($f, 1) != "\n") $lines -= 1;

            // Start reading
            $output = '';
            $chunk = '';
            // While we would like more
            while (ftell($f) > 0 && $lines >= 0) {
                    // Figure out how far back we should jump
                    $seek = min(ftell($f), $buffer);
                    // Do the jump (backwards, relative to where we are)
                    fseek($f, -$seek, SEEK_CUR);
                    // Read a chunk and prepend it to our output
                    $output = ($chunk = fread($f, $seek)) . $output;
                    // Jump back to where we started reading
                    fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
                    // Decrease our line counter
                    $lines -= substr_count($chunk, "\n");
            }
            // While we have too many lines
            // (Because of buffer size we might have read too many)
            while ($lines++ < 0) {
                    // Find first newline and remove all text before that
                    $output = substr($output, strpos($output, "\n") + 1);
            }
            // Close file and return
            fclose($f);
            return trim($output);
    }    
     
    /*
     * update our stocks
     */
    function updateStocks($my_file){
        
        $em = $this->em;
        $ktap_unique_smbid =array();
        $erori = '';  
        
        //extragem ultimele 20000 de linii din fisierul csv
        if($ktares=$this->tailCustom($my_file,20000)){
           $lines = explode("\n",$ktares); 
        }        
        
        if (isset($lines)) {      
            
            foreach($lines as $line){
                
                $line = explode(';', $line);
                
                $line = array_map('trim', $line);
                
                if(count($line) !== 2) {
                    
                    $erori = 'Separator gresit!';
                    continue;
                    
                } else {
                    
                    $insert=true;                    

                    $cod = trim($line[0]);
                    $cant = trim($line[1]);
                    $warehouseId = 1;
                    $ktnow = new \DateTime('now');  
                    
                    //look for item-warehouse
                    $itemWarehouse=array();
                    $qb = $em->createQueryBuilder();
                    $qb -> select(array('iw','i','w'))                        
                                ->from('AppBundle:ItemWarehouse', 'iw')       
                                ->leftJoin('iw.item', 'i')
                                ->leftJoin('iw.idWarehouse', 'w')
                                ->andWhere($qb->expr()->eq('i.reference', '?1'))  
                                ->andWhere($qb->expr()->eq('w.id', '?2'))  
                                ->setParameter(1, $cod)
                                ->setParameter(2, $warehouseId);

                    $itemWarehouse = $qb->getQuery()->getResult();
                    
                    if($itemWarehouse){                        
                        foreach($itemWarehouse as $entity){
                            $entity->setQuantity($cant);
                            $entity->setDatUpd($ktnow);
                            $insert=false;
                            break;
                        }
                    }
                    
                    $item = $em->getRepository('AppBundle:Item')->findOneBy(array('reference'=>$cod));
                    $warehouse = $em->getRepository('AppBundle:ItemWarehouse')->find(1);
                    
                    // adaugam in ItemWarehouse
                    if($insert && $item && $warehouse){  
                        $itemWarehouse = new ItemWarehouse();  
                        $itemWarehouse->setItem($item);                       
                        $itemWarehouse->setIdWarehouse($warehouse);  
                        $itemWarehouse->setDatCre($ktnow); 
                        $itemWarehouse->setDatUpd($ktnow); 
                        $em->persist($itemWarehouse);  
                    }   
                    // pentru unicitate adaugam in $ktap_unique_smbid
                    $ktap_unique_smbid[] = $cod; 
                }
            } 
            $em->flush();
            $em->clear();  
            //unlink($my_file);
        } else {
            $erori = 'Fisierul csv nu poate fi deschis!';          
        }         

        if ($erori){            
            return $erori;   
        }
    }
    
    /**
     * Process entities from csv.
     *
     */
    public function productCsv($my_file)
    {
        $em = $this->em;
        $ktap_unique_smbid =array();
        $erori = '';  
        //extragem ultimele 20000 de linii din fisierul csv
        if($ktares=$this->tailCustom($my_file,20000)){
           $lines = explode("\n",$ktares); 
        }  
        $myswitch = true;

        $ktap_unique = array();
        $today = new \DateTime("now"); 
        ini_set('memory_limit', '-1');
        if (isset($lines)) {      
            
            foreach($lines as $line){
                
                $line = explode(';', $line);
                
                if(count($line)<=1) continue; //wrong sep => skip line
                
                // skip first line
                if ($myswitch) {
                    $myswitch=false;
                    continue;
                }
                
                $line = array_map('trim', $line);

                
                /*
                 * in cazul in care in fisierul csv sunt mai multe linii
                 * cu aceeasi reference, noi o folosim numai pe prima
                 * pentru asta folosim $ktap_unique
                 * 
                 */                        
                if(!in_array($line[0], $ktap_unique)){
                        
                    $ktap_unique[] = $line[0];
                                        
                    $product = $em->getRepository('AppBundle:Product')->findOneBy(array('reference'=>$line[0]));
                    
                    /*
                    * daca nu exista deja product adaugam unul nou
                    */

                    if(!$product){
                       
                        $product = new Product(); 
                        $product->setReference($line[0]);
                        $product->setDatCre($today);  
                          
                    }      
                    
                    // add product lang bg
                    $productLang = new ProductLang; 
                    $productLang->setProduct($product);
                    $productLang->setName($line[1]);
                    $productLang->setLang('bg');
                    $em->persist($productLang);
                    
                    // add product lang ro
                    $productLang = new ProductLang; 
                    $productLang->setProduct($product);
                    $productLang->setName($line[2]);
                    $productLang->setLang('ro');    
                    $em->persist($productLang);
                    
                    // add product lang en
                    $productLang = new ProductLang; 
                    $productLang->setProduct($product);
                    $productLang->setName($line[3]);
                    $productLang->setLang('en');   
                    $em->persist($productLang);
                    
                    
                    // add product features 
                    $feature_material = $em->getRepository('AppBundle:Feature')->findOneBy(array('bg'=>$line[4],'ro'=>$line[5],'en'=>$line[6]));    
                    
                    if($feature_material){
                            $product->addFeature($feature_material);
                    }  
                    
                    $feature_color = $em->getRepository('AppBundle:Feature')->findOneBy(array('bg'=>$line[7],'ro'=>$line[8],'en'=>$line[9]));
                    if($feature_color){
                            $product->addFeature($feature_color);
                    }  
                    
                    $feature_size = $em->getRepository('AppBundle:Feature')->findOneBy(array('bg'=>$line[10],'ro'=>$line[11],'en'=>$line[12]));
                    if($feature_size){
                            $product->addFeature($feature_size);
                    }   
                    
                    $category = $em->getRepository('AppBundle:Category')->find(1);
                    if($category){
                       $product->addCategory($category);
                    }
                    
                    $unitMeasure = $em->getRepository('AppBundle:UnitMeasure')->find(1);
                    if($unitMeasure){
                       $product->setUnitMeasure($unitMeasure);
                    }                    
                    
                    if(array_key_exists(13, $line))
                            $product->setEan($line[13]);
                    
                    if(array_key_exists(14, $line))
                        $product->setSalePrice($line[14]);
                   // $product->setManufacturer($line[15]);

                                   
                    $product->setDatUpd($today); 
                    $em->persist($product);
                    
                }
            }
        } else {
            return 'Fisierul csv nu poate fi deschis!';          
        }
        
        $em->flush();
        $em->clear(); 
        
        return $wrong_csv_lines;
    }      
}
