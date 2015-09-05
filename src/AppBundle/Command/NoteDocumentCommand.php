<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use AppBundle\Entity\Diploma;

class NoteDocumentCommand extends ContainerAwareCommand
{
    public $output;

    protected function configure()
    {
        $this
            ->setName('app:notedocument')
            ->setDescription('Add xml tags to document provided')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The document unique identifier'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fs = new Filesystem();

        $this->output = $output;
        $id = $input->getArgument('id');

        $db = $this->getContainer()->get('doctrine');
        $diploma = $db->getRepository('AppBundle:Diploma')
            ->find((integer)$id);

        if(!$diploma){
            $output->writeln("ERROR: Invalid file id");
            return;
        }
        else{
            $text = utf8_decode(file_get_contents($diploma->getPath()));
            if($text != FALSE) {
                $output->writeln("Starting to parse document " . $id);
                $text = preg_replace('/(<\/p>\n|<\/p>\z)/', '', $text);
                $text = preg_replace('/\n/',"",$text);
                $text = preg_split('/<p>/', $text, -1, PREG_SPLIT_NO_EMPTY);

                $current_article_no = 0;
                $current_chapter_no = "";
                $current_section_no = "";
                $current_appendum_no = "";
                $final = '<?xml version="1.0" encoding="UTF-8"?>';
                $inside_quotes=false;
                $new_article = false;
                $new_section = false;
                $new_chapter = false;
                $new_appendum = false;
                $title=true;
                foreach ($text as $line) {
                    if($title){                                                                                          // does not process first line, instead add header
                        $final = $final . "\n".'<document lastModification="'.time().'" parserVersion="0.1.1">';
                        $title = false;
                    }
                    else if(preg_match('/\Ade [0-9]{1,2} de [a-zA-Z]+/',$line)){}                                        // does not include header date
                    else if($new_article == true){                                                                       // adding title to the article tag
                        $final = $final . 'title="' . $line . '">';
                        $new_article = false;
                    }
                    else if($new_section == true){                                                                       // adding title to the article tag
                        $final = $final . 'title="' . $line . '">';
                        $new_section = false;
                    }
                    else if($new_chapter == true){                                                                       // adding title to the article tag
                        $final = $final . 'title="' . $line . '">';
                        $new_chapter = false;
                    }
                    else if($new_appendum == true){                                                                       // adding title to the article tag
                        if(preg_match('/\A^[^a-z]*\z/',$line)) {
                            $final = $final.'title="'.$line.'">';
                            $new_appendum = false;
                        }
                        else if(preg_match('/\A\(.*\)\z/',$line)){
                            $line = preg_replace('/(\(|\))/',"",$line);
                            $final = $final.'ref="'.$line.'" ';
                        }
                    }
                    else{                                                                                                 // adding a new line
                        if ($inside_quotes && preg_match("/�/", $line))                                                   //end of quoting    -> restarting annotation
                            $inside_quotes = false;
                        else if (!$inside_quotes && preg_match('/�Artigo [0-9]+\.�/',$line))                              // start of quoting -> stopping annotation
                            $inside_quotes = true;
                        else if (!$inside_quotes && preg_match('/Artigo [0-9]+\.�/', $line)) {                      // detected "Artigo"
                            $temp = preg_split('/( |\.)/', $line);
                            $line = '<artigo no="'.$temp[1].'" ';
                            $new_article = true;
                            if ($current_article_no != 0) {
                                $final = $final."\n</artigo>";
                            }
                            $current_article_no = $temp[1];
                        }
                        else if (!$inside_quotes && preg_match('/SEC��O ([A-Z]+|�NICA)/', $line)) {                         // detected "SECCAO"
                            $temp = preg_split('/ /', $line);
                            $line = '<seccao no="'.$temp[1].'" ';
                            $new_section = true;
                            if ($current_article_no != 0) {
                                $final = $final."\n</artigo>";
                                $current_article_no = 0;
                            }
                            if ($current_section_no != "") {
                                $final = $final."\n</seccao>";
                                $current_section_no = "";
                            }
                            $current_section_no = $temp[1];
                        }
                        else if (!$inside_quotes && preg_match('/CAP�TULO [A-Z]+/', $line)) {                       // detected "CAPITULO"
                            $temp = preg_split('/ /', $line);
                            $line = '<capitulo no="'.$temp[1].'" ';
                            $new_chapter = true;
                            if ($current_article_no != 0) {
                                $final = $final."\n</artigo>";
                                $current_article_no = 0;
                            }
                            if ($current_section_no != "") {
                                $final = $final."\n</seccao>";
                                $current_section_no = "";
                            }
                            if ($current_chapter_no != "") {
                                $final = $final."\n</capitulo>";
                            }
                            $current_chapter_no = $temp[1];
                        }
                        else if(!$inside_quotes && preg_match('/\A(Aprovada em )?[0-9]{2} de [a-zA-Z]+ de [0-9]{4}/', $line)) {    //detected final date
                            if ($current_article_no != 0) {
                                $final = $final."\n</artigo>";
                                $current_article_no = 0;
                            }
                            if ($current_section_no != "") {
                                $final = $final."\n</seccao>";
                                $current_section_no = "";
                            }
                            if ($current_chapter_no != "") {
                                $final = $final."\n</capitulo>";
                                $current_chapter_no = "";
                            }
                        }
                        else if(!$inside_quotes && preg_match('/\AANEXO( [A-Z]+)?\z/', $line)) {                              //detected anexo
                            $temp = preg_split('/ /', $line);
                            if(sizeof($temp)>1)
                                $current_appendum_no = "I";
                            else
                                $current_appendum_no = $temp[1];

                            $line = '<anexo no="'.$current_appendum_no.'" ';

                            $new_appendum = true;
                            if ($current_article_no != 0) {
                                $final = $final."\n</artigo>";
                                $current_article_no = 0;
                            }
                            if ($current_section_no != "") {
                                $final = $final."\n</seccao>";
                                $current_section_no = "";
                            }
                            if ($current_chapter_no != "") {
                                $final = $final."\n</capitulo>";
                                $current_chapter_no = "";
                            }
                            if ($current_appendum_no != "") {
                                $final = $final."\n</anexo>";
                            }
                        }
                        $final = $final . "\n" . $line;
                    }
                }
                if($current_article_no!=0) $final = $final . "\n" . "</artigo>";
                if($current_section_no!="") $final = $final . "\n" . "</seccao>";
                if($current_chapter_no!="") $final = $final . "\n" . "</capitulo>";
                if($current_appendum_no!="") $final = $final . "\n" . "</anexo>";
                $final = $final . "\n</document>";
                $fs->dumpFile('bin/docs/parsed/'.$id.'.xml',utf8_encode($final));

                $output->writeln("Successfully parsed document " . $id);
            }
        }
    }
}