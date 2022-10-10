#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use LdapRecord\Connection;
use LdapRecord\Container;
use League\Csv\CharsetConverter;
use League\Csv\Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

const LDAP_SERVER_ADDRESS = 'proxy-ubtrz.uni-bayreuth.de';
const LDAP_SERVER_PORT = 389;
const LDAP_BASE_DN = 'o=uni-bayreuth';

final class Student
{
    private string $id;

    /**
     * @param string $firstName
     * @param string $lastName
     * @param string $tmpMailAddress
     * @param string|null $ubtMailAddress
     */
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $tmpMailAddress,
        public ?string $ubtMailAddress = null
    ) {
        $this->id = crc32($this->firstName . $this->lastName);
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param string $ubtMailAddress
     * @return void
     */
    public function setUbtMailAddress(string $ubtMailAddress): void
    {
        $this->ubtMailAddress = $ubtMailAddress;
    }
}

final class Students implements IteratorAggregate
{
    /** @var Student[] */
    private array $students = [];

    public function add(Student $student): void
    {
        if (array_key_exists($student->getId(), $this->students)) {
            return;
        }

        $this->students[$student->getId()] = $student;
    }

    /**
     * @return Traversable<string, Student>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->students);
    }

    /**
     * @return Generator<int, string[]>
     */
    public function toListservCompatibleData(): Generator
    {
        foreach ($this->students as $student) {
            yield [$student->tmpMailAddress, $student->firstName, $student->lastName];

            if (null !== $student->ubtMailAddress) {
                yield [$student->ubtMailAddress, $student->firstName, $student->lastName];
            }
        }
    }
}

(new SingleCommandApplication())
    ->setName('Extract mail addresses from the UBT LDAP server.')
    ->addArgument('input-directory', InputArgument::REQUIRED, 'The directory where the input files are placed.')
    ->addArgument('first-name-column', InputArgument::REQUIRED, 'The "name" (first row) of the column for the first name.')
    ->addArgument('last-name-column', InputArgument::REQUIRED, 'The "name" (first row) of the column for the last name.')
    ->addArgument('tmp-mail-column', InputArgument::REQUIRED, 'The "name" (first row) of the column for the temporary email.')
    ->addArgument('ubt-mail-column', InputArgument::REQUIRED, 'The "name" (first row) of the column for the ubt email.')
    ->addArgument('output-file-name', InputArgument::REQUIRED, 'The name of the output txt file.')
    ->setCode(function (InputInterface $input, OutputInterface $output) {
        $io = new SymfonyStyle($input, $output);

        $io->text('Extracting data from files ...');

        $finder = new Finder();
        $finder->files()->in($input->getArgument('input-directory'))->name(['*.xls', '*.xlsx', '*.csv']);

        $students = new Students();
        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            $fileType = $file->getExtension();

            $reader = IOFactory::createReader(ucfirst($fileType));
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $data = $spreadsheet->getActiveSheet()->toArray();

            $header = array_shift($data);
            // Because the actual column indexes are generated here, the input files can contain different column orders.
            $firstNameColumnIndex = array_search($input->getArgument('first-name-column'), $header);
            $lastNameColumnIndex = array_search($input->getArgument('last-name-column'), $header);
            $tmpMailColumnIndex = array_search($input->getArgument('tmp-mail-column'), $header);

            foreach ($data as $dataset) {
                $students->add(new Student($dataset[$firstNameColumnIndex], $dataset[$lastNameColumnIndex], $dataset[$tmpMailColumnIndex]));
            }
        }

        $io->text('Establishing LDAP connection ...');

        $connection = new Connection([
            'hosts' => [LDAP_SERVER_ADDRESS],
            'port' => LDAP_SERVER_PORT,
            'base_dn' => LDAP_BASE_DN,
            'username' => null,
            'password' => null,
        ]);
        $connection->connect();
        Container::addConnection($connection);

        $io->text('Querying mail addresses from LDAP ...');

        foreach ($students as $student) {
            $queryBuilder = $connection->query();
            $queryBuilder->whereEquals('objectClass', 'Person')
                ->whereContains('preferredName', $student->firstName)
                ->whereContains('sn', $student->lastName);
            $results = $queryBuilder->get();

            if (0 === count($results)) {
                $io->warning(sprintf('The mail address of "%s %s" could not be found.', $student->firstName, $student->lastName));
                continue;
            }

            $mails = $results[0]['mail'];
            unset($mails['count']);
            $chosenMails = array_values(
                array_filter($mails, static function (string $mail) {
                    return !preg_match('/bt\d{6}/', $mail);
                })
            );
            if (0 === count($chosenMails)) {
                $io->warning(sprintf('No proper mail address could be found for "%s %s".', $student->firstName, $student->lastName));
                continue;
            }
            $student->setUbtMailAddress($chosenMails[0]);
        }

        $io->text('Writing results to Listserv compatible TXT file ...');

        $dataWriter = Writer::createFromString('php://output', 'w');
        $dataWriter->setDelimiter("\t");
        // The encoding required by Listserv to prevent miscoded characters was determined by trial & error.
        $encoder = (new CharsetConverter())->outputEncoding('ISO-8859-1');
        $dataWriter->addFormatter($encoder);

        $dataWriter->insertAll($students->toListservCompatibleData());

        // The "native" write mechanic of the Writer class uses fputcsv, which results in unwanted quotation marks in fields containing spaces.
        // Therefore, the data is printed to the PHP output and processed as required.
        $output = $dataWriter->toString();
        $output = str_replace('php://output', '', $output);
        $output = str_replace('"', '', $output);

        $outputFilePath = __DIR__ . 'command.php/' . $input->getArgument('output-file-name');
        $outputFile = fopen($outputFilePath, 'w+');
        fputs($outputFile, $output);
        fclose($outputFile);

        $io->success(sprintf('%d entries were written to the output file "%s".', substr_count($output, "\n"), $outputFilePath));

        return Command::SUCCESS;
    })
    ->run();