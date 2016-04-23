<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Entity\Product;
use AppBundle\Entity\ProductWarehouse;
use AppBundle\Entity\Warehouse;

class KtapAddProductsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('ktap:addprod')
            ->setDescription('Add products from csv.')
           
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        //$my_file = '/home/linksport/kronsento/tmpdata/_stoc.csv'; //production path
        $my_file = 'docs/uploads/csvFiles/2016-04-20_17-08-13_2016-03-01_14-37-33_items_all.csv';//local
        
        $erori = '';
        
        $erori = $this->getContainer()->get('ktap.synch')->productCsv($my_file); 
        
        if ($erori){
            $date=new \DateTime();
            echo $date->format('Y-m-d H:i:s').' '.$erori."\n";   
        }  

    }
}