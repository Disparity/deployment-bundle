<?php

namespace Disparity\DeploymentBundle\Command;

use Doctrine\DBAL\Migrations\Configuration\Configuration as DoctrineMigrationConfiguration;
use Doctrine\Bundle\MigrationsBundle\Command\DoctrineCommand;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\ProcessBuilder;


/**
 */
class MigrateCommand extends ContainerAwareCommand
{

	/**
	 * @var DoctrineMigrationConfiguration 
	 */
	private $doctrineMigrationConfiguration;


	/**
	 * @inheritdoc
	 */
	protected function configure()
	{
		$this
				->setName('disparity:deployment:migrate')
				->setDescription('Checkout to branch/tag/commit, execute(up and down) all doctrine migrations and install composer dependencies')
				->addOption('display-sql', null, InputOption::VALUE_NONE, 'Show sql queries instead of executing it. It also includes queries to mark versions as migrated/not migrated.')
				->addOption('clean-working-copy', null, InputOption::VALUE_NONE, 'Clean working copy: remove local changes, prune remote branches and remove other local branches.')
				->addArgument('hash', InputArgument::OPTIONAL, 'Tag/branch name or commit hash. If not specified then update current branch')
		;
	}


	/**
	 * @inheritdoc
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$sqlBuffer = $input->getOption('display-sql') ? fopen('php://temp', 'w+') : null;

		if ($input->getOption('clean-working-copy')) { // Remove local changes
			$this->runProcess(['git', 'reset', '--hard', 'HEAD'], $output);
			$this->runProcess(['git', 'clean', '-f', '-d'], $output);
		}

		$this->runProcess(['git', 'fetch', '--all', $input->getOption('clean-working-copy') ? '--prune' : null], $output);
		$destination = $this->computeDestination($input);

		foreach ($this->findRemovedMigration($destination[1], $destination[2]) as $migration) {
			$args = [
				'--write-sql' => $sqlBuffer ? $tmp = tempnam(sys_get_temp_dir(), '') : false,
				'--up'    => false,
				'--down'  => true,
				'version' => $migration,
			];
			$this->runCommand('doctrine:migration:execute', $args, $input, $output);
			if ($sqlBuffer) {
				stream_copy_to_stream(fopen($tmp, 'r'), $sqlBuffer);
				fwrite($sqlBuffer, "DELETE FROM `{$this->getDMC()->getMigrationsTableName()}` WHERE version = '{$migration}';\n");
			}
		}

		if ($destination[0] === 'branch') {
			if ($destination[3] === $destination[1]) { // update branch
				$this->runProcess(['git', 'reset', '--hard', $destination[2]], $output);
			} else { // switch to branch
				$this->runProcess(['git', 'branch', '--track', '--force', $destination[3], $destination[2]], $output);
				$this->runProcess(['git', 'checkout', $destination[3]], $output);
			}
		} else {
			$this->runProcess(['git', 'checkout', '-f', $destination[2]], $output);
		}

		if ($sqlBuffer) {
			$newMigrationsSQL = implode(", ", array_map(function ($version) {return "('{$version}')";}, $this->findNewMigration()));
			$this->runCommand('doctrine:migration:migrate', ['--write-sql' => $tmp = tempnam(sys_get_temp_dir(), ''),], $input, $output);
			stream_copy_to_stream(fopen($tmp, 'r'), $sqlBuffer);
			if ($newMigrationsSQL) {
				fwrite($sqlBuffer, "INSERT INTO `{$this->getDMC()->getMigrationsTableName()}` (`version`) VALUES {$newMigrationsSQL}");
			}
		} else {
			$this->runCommand('doctrine:migration:migrate', [], $input, $output);
		}

		if ($input->getOption('clean-working-copy')) { // Remove local branches
			foreach ($this->runProcess(['git', 'rev-parse', '--abbrev-ref', "--branches=*"]) as $branch) {
				if ($destination[1] === $branch) {
					continue;
				}
				$this->runProcess(['git', 'branch', '-D', $branch]);
			}
		}

		$this->runProcess([
			'composer', 'install',
			$input->getOption('env') === 'dev' ? '--dev' : '--nodev',
			$input->getOption('env') !== 'dev' ? '--optimize-autoloader' : null,
			$input->getOption('no-interaction') ? '--no-interaction' : null,
			$input->getOption('verbose') ? '--verbose' : null,
		], $output);

		if ($sqlBuffer) {
			rewind($sqlBuffer);
			$output->writeln('<info> == Doctrine Migration sql queries ==</info>');
			$output->writeln(stream_get_contents($sqlBuffer));
			fclose($sqlBuffer);
		}

		// @todo check and display warning if deployment bundle not exists after composer install

		$output->writeln("<info>Migrated to {$destination[0]}: {$destination[2]}</info>");
	}


	/**
	 * @param InputInterface $input
	 * @return string[] [hash type(commit, tag, branch), current branch name/hash, new hash/origin, specified hash]
	 * @throws \Exception
	 */
	private function computeDestination(InputInterface $input)
	{
		list($current) = $this->runProcess(['git', 'rev-parse', 'HEAD']);
		list($currentBranch) = $this->runProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
		$currentBranch = $currentBranch === 'HEAD' ? $current : $currentBranch;

		$hash = $input->getArgument('hash') ? : $currentBranch;
		if ($hash === $current) {
			throw new \Exception('You are not currently on a branch. Update is not available.'); // @todo fix exception class
		}

		if (in_array($hash, $this->runProcess(['git', 'tag']))) {
			return ['tag', $current, $hash];
		}

		if ($this->runProcess(['git', 'rev-parse', '--abbrev-ref', "--branches=*{$hash}"])) { //@todo fix mask
			// @todo handle the case with switching to a local branch without the remote.
			list($origin) = $this->runProcess(['git', 'rev-parse', '--abbrev-ref', "{$hash}@{u}"]);
			return ['branch', $currentBranch, $origin, $hash];
		}
		foreach ($this->runProcess(['git', 'rev-parse', '--abbrev-ref', "--remotes=*/{$hash}"]) as $origin) {
			return ['branch', $currentBranch, $origin, $hash];
		}

		return ['commit', $current, $hash];
	}


	/**
	 * @param string $from
	 * @param string $to
	 * @return string[]
	 */
	private function findRemovedMigration($from, $to)
	{
		$migrations = array_map(function($path) {
			return substr(pathinfo($path, PATHINFO_FILENAME), 7); // Version*
		}, $this->runProcess(['git', 'diff', '--diff-filter=DM', '--name-only', '--summary', $from, $to, '--', $this->getDMC()->getMigrationsDirectory()]));
		$migrations = array_diff($migrations, $this->getDMC()->getMigratedVersions());
		rsort($migrations);
		return $migrations;
	}


	/**
	 * @return string[]
	 */
	private function findNewMigration()
	{
		$this->getDMC()->registerMigrationsFromDirectory($this->getDMC()->getMigrationsDirectory());
		return array_keys($this->getDMC()->getMigrationsToExecute('up', $this->getDMC()->getLatestVersion()));
	}


	/**
	 * @return DoctrineMigrationConfiguration
	 */
	private function getDMC()
	{
		if (!$this->doctrineMigrationConfiguration) {
			if (!$this->getApplication()->getHelperSet()->has('connection')) {
				throw \Exception('Database connection not exists.'); // @todo fix exception class
			}
			$this->doctrineMigrationConfiguration = new DoctrineMigrationConfiguration($this->getHelper('connection')->getConnection());
			DoctrineCommand::configureMigrations($this->getContainer(), $this->doctrineMigrationConfiguration);
		}
		return $this->doctrineMigrationConfiguration;
	}


	/**
	 * @param array $args
	 * @param OutputInterface $output
	 * @return string[]
	 */
	private function runProcess(array $args, OutputInterface $output = null)
	{
		$output = $output ? : new BufferedOutput();
		ProcessBuilder::create(array_filter('is_null', $args))->getProcess()->mustRun(function ($type, $data) use($output) {
			$output->write($data);
		});
		return $output instanceof BufferedOutput ? $this->extractResult($output) : null;
	}


	/**
	 * @param string $commandName
	 * @param array $options
	 * @param InputInterface $input
	 * @param OutputInterface $output [OPTIONAL]
	 * @return string[]|void
	 * @throws \Exception
	 */
	private function runCommand($commandName, array $options, InputInterface $input, OutputInterface $output = null)
	{
		$output = $output ? : new BufferedOutput();
		$exitCode = $this->getApplication()->find($commandName)->run(new ArrayInput(['command' => $commandName,] + $options + [
			'--no-interaction' => $input->getOption('no-interaction'),
			'--env'            => $input->getOption('env'),
			'--verbose'        => $input->getOption('verbose'),
		]), $output);
		if ($exitCode === 0) {
			return $output instanceof BufferedOutput ? $this->extractResult($output) : null;
		}
		throw new \Exception("The command \"{$commandName}\" failed.'\nExit Code: {$exitCode}"); // @todo fix exception class
	}


	/**
	 * @param BufferedOutput $output
	 * @return string[]
	 */
	private function extractResult(BufferedOutput $output)
	{
		return array_filter(array_map('rtrim', explode("\n", $output->fetch())));
	}
}
