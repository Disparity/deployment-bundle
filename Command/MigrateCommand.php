<?php

namespace Disparity\DeploymentBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Console\Input\ArrayInput;

/**
 */
class MigrateCommand extends ContainerAwareCommand
{


	protected function configure()
	{
		$this
				->setName('disparity:deployment:migrate')
				->setDescription('Checkout to branch/tag/commit, execute(up and down) all doctrine migrations and install composer dependencies')
				->addOption('write-sql', null, InputOption::VALUE_NONE, 'The path to output the migrations SQL file instead of executing it. Version will be marked as migrated/not migrated.')
				->addOption('clean-working-copy', null, InputOption::VALUE_NONE, 'Clean working copy: remove local changes, prune remote branches and remove other local branches.')
				->addArgument('hash', InputArgument::OPTIONAL, 'Tag/branch name or commit hash. If not specified then update current branch')
		;
	}


	/**
	 * @inheritdoc
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
	{
//		if ($input->getOption('clean-working-copy')) { // Remove local changes
//			;
//		}

		$this->runProcess(['git', 'fetch', '--all', $input->getOption('clean-working-copy') ? '--prune' : ''], $output);
		$destination = $this->computeDestination($input);

		foreach ($this->findRemovedMigration($destination[1], $destination[2]) as $migration) {
			$this->runCommand('doctrine:migration:execute', [
				'--write-sql'      => $input->getOption('write-sql'),
				'--up'    => false,
				'--down'  => true,
				'version' => $migration,
			], $input, $output);
//			if ($input->getOption('write-sql')) { // @todo synchronize with doctrine:migration:execute write-sql
//				// use doctrine:migrations:version ?!
//				$output->writeln("DELETE FROM `{$this->getParameter('doctrine_migrations.table_name')}` WHERE version = '{$migration}'");
//			}
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
		$this->runCommand('doctrine:migration:migrate', [
			'--write-sql'      => $input->getOption('write-sql'),
		], $input, $output);
//		if ($input->getOption('write-sql')) { // @todo synchronize with doctrine:migration:migrate write-sql
//			;
//		}

//		if ($input->getOption('clean-working-copy')) { // Remove local branches
//			;
//		}

		$this->runProcess([
			'composer', 'install',
			$input->getOption('env') === 'dev' ? '--dev' : '--nodev',
			$input->getOption('env') !== 'dev' ? '--optimize-autoloader' : '',
			$input->getOption('no-interaction') ? '--no-interaction' : '',
			$input->getOption('verbose') ? '--verbose' : '',
		], $output);

		$output->writeln("Migrated to {$destination[2]}");
	}


	private function computeDestination(InputInterface $input)
	{
		list($current) = $this->runProcess(['git', 'rev-parse', 'HEAD']);
		list($currentBranch) = $this->runProcess(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
		$currentBranch = $currentBranch === 'HEAD' ? $current : $currentBranch;

		$hash = $input->getArgument('hash') ? : $currentBranch;
		if ($hash === $current) {
			throw new \Exception('You are not currently on a branch. Update is not available.');
		}

		if (in_array($hash, $this->runProcess(['git', 'tag']))) {
			return ['tag', $current, $hash];
		}

		if ($this->runProcess(['git', 'rev-parse', '--abbrev-ref', "--branches={$hash}"])) {
			// @todo handle the case with switching to a local branch without the remote.
			list($origin) = $this->runProcess(['git', 'rev-parse', '--abbrev-ref', "{$hash}@{u}"]);
			return ['branch', $currentBranch, $origin, $hash];
		}
		foreach ($this->runProcess(['git', 'rev-parse', '--abbrev-ref', "--remotes=*/{$hash}"]) as $origin) {
			return ['branch', $currentBranch, $origin, $hash];
		}

		return ['commit', $current, $hash];
	}


	private function findRemovedMigration($from, $to)
	{
		$migrations = array_map(function($path) {
			return substr(pathinfo($path, PATHINFO_FILENAME), 7); // Version*
		}, $this->runProcess(['git', 'diff', '--diff-filter=D', '--name-only', '--summary', $from, $to, '--', $this->getParameter('doctrine_migrations.dir_name')]));
		rsort($migrations);
		return $migrations;
	}


	private function runProcess($args, OutputInterface $output = null)
	{
		$outputData = [];
		ProcessBuilder::create(array_filter($args))->getProcess()->mustRun(function ($type, $data) use($output, &$outputData) {
			$outputData[] = rtrim($data);
			if (!$output) { // @todo use BufferedOutput if null?!
				return;
			}
			$output->write($data);
		});
		return $outputData;
	}


	private function runCommand($commandName, array $options, InputInterface $input, OutputInterface $output)
	{
		return $this->getApplication()->find($commandName)->run(new ArrayInput(['command' => $commandName,] + $options + [
			'--no-interaction' => $input->getOption('no-interaction'),
			'--env'            => $input->getOption('env'),
			'--verbose'        => $input->getOption('verbose'),
		]), $output);
	}


	private function getParameter($name)
	{
		return $this->getContainer()->getParameter($name);
	}

}
