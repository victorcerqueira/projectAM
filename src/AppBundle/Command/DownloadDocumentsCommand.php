<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use AppBundle\Entity\Diploma;

class DownloadDocumentsCommand extends ContainerAwareCommand
{
    public $output;
    public $processes = array();
    protected function configure()
    {
        $this
            ->setName('app:downloaddocuments')
            ->setDescription('Download daily documentation from dre.pt')
            ->addArgument(
                'date',
                InputArgument::OPTIONAL,
                'Date, leave blank to download todays documentation'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $date = $input->getArgument('date');

        if($date){
            if( !\DateTime::createFromFormat('Y-m-d', $date))
            {
                $output->writeln('Invalid date format');
                return;
            }

        }
        else{
            $date = (new \DateTime())->format('Y-m-d');
        }

        $this->downloadDocumentation($date);

        foreach($this->processes as $process) {
            $process->wait();
        }
    }

    protected function downloadDocumentation($day)
    {

        $url = 'https://dre.pt/web/guest/pesquisa-avancada/-/asearch/advanced/maximized?dataPublicacao='.$day.'&perPage=10000&types=DR';

        $crawler = $this->getDomCrawlerFromUrl($url);


        $crawler = $crawler->filterXPath('//div[@class="search-result"]/ul/li');

        $array = $crawler->each(function (Crawler $node, $i) {
            return $node->filterXPath('//a')->attr('href');
        });

        foreach($array as $current){
            $this->output->writeln("Reading ".$url);
            $this->getDocumentUrlFromPage($current);
        }


    }

    protected function getDocumentUrlFromPage($url){

        $crawler = $this->getDomCrawlerFromUrl($url);

        $subtitle = $crawler->filterXPath('//div[@class="list"]/div/div');

        $no_pages = 1;
        if($subtitle->count() > 0){
            $temp = explode(" ", $subtitle->first()->text());
            $no_pages = intval(end($temp));
        }

        $array = array();
        for($x=1; $x<=$no_pages; $x++) {
            $this->output->writeln("Reading page no ".$x);
            if ($x != 1) {
                $temp = explode("details/", $url);
                $new_url = $temp[0]."details/".$x."/".$temp[1];
                $crawler = $this->getDomCrawlerFromUrl($new_url);
            }
            $array = $crawler->filterXPath('//div[@class="list"]/ul/li/a/span')->each(
                    function (Crawler $node, $i) {
                        return $node->text();
                    }
            );

            $this->storeDocuments($array);
        }
    }

    protected function storeDocuments($array){
        $fs = new Filesystem();
        $count = 0;
        $db = $this->getContainer()->get('doctrine');
        $em = $db->getManager();



        foreach($array as $id){
            $crawler = $this->getDocumentFromId($id);

            $date = \DateTime::createFromFormat('Y-m-d',$this->getDate($crawler));
            $type = $this->getType($crawler);
            $code = $this->getCode($crawler);
            $entity = $this->getEntity($crawler);
            $summary = $this->getSummary($crawler);
            $path = 'bin/docs/raw/'.$id.'.xml';
            $text = $this->getText($crawler);

            if(!$fs->exists($path)) {
                $fs->dumpFile($path, $text);
                $count++;
            }
            $diploma = $db->getRepository('AppBundle:Diploma')
                ->find((integer)$id);

            if(!$diploma)
                $diploma = new Diploma();
            $diploma->setId($id);
            $diploma->setPath($path);
            $diploma->setDate($date);
            $diploma->setType($type);
            $diploma->setCode($code);
            $diploma->setEntity($entity);
            $diploma->setSummary($summary);
            $em->persist($diploma);

            $this->output->writeln("Added ".$type." ".$code." with id ".$id);
            $process = new Process('php app/console app:notedocument '.$id);
            $process->start();
            array_push($this->processes,$process);
        }
        $em->flush();
    }

    protected function getDocumentFromId($id){

        $url = 'https://dre.pt/home/-/dre/'.$id.'/details/3/maximized?serie=II&parte_filter=31';

        $crawler = $this->getDomCrawlerFromUrl($url);

        return $crawler->filterXPath('//div[@id="maincontent"]');
    }

    protected function getDate($crawler){
        return $crawler->filterXPath('//div/ul/li[@class="dataPublicacao"]/span/following-sibling::text()')->text();
    }

    protected function getType($crawler){
        return $crawler->filterXPath('//div/ul/li[@class="tipoDiploma.tipo"]/span/following-sibling::text()')->text();
    }

    protected function getCode($crawler){
        return $crawler->filterXPath('//div/ul/li[@class="numero"]/span/following-sibling::text()')->text();
    }

    protected function getEntity($crawler){
        return $crawler->filterXPath('//div/ul/li[@class="emissor.designacao"]/span/following-sibling::text()')->text();
    }

    protected function getSummary($crawler){
        return $crawler->filterXPath('//div/ul/li[@class="formatedSumarioWithLinks"]/p')->text();
    }

    protected function getText($crawler){
        return utf8_decode($crawler->filterXPath('//div/ul/li[@class="formatedTextoWithLinks"]/div')->html());
    }

    protected function getDomCrawlerFromUrl($url){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        return new Crawler($result);
    }

}
