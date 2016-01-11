<?php
namespace Robo\Task;

use Robo\Result;
use Robo\Task\BaseTask;
use Robo\Contract\TaskInterface;

/**
 * Extend StackBasedTask to create a Robo task that
 * runs a sequence of commands.
 *
 * This is particularly useful for wrapping an existing
 * object-oriented API.  Doing it this way requires
 * less code than manually adding a method for each wrapped
 * function in the delegate.  Additionally, wrapping the
 * external class in a StackBasedTask creates a loosely-coupled
 * interface -- i.e. if a new method is added to the delegate
 * class, it is not necessary to update your wrapper, as the
 * new functionality will be inherited.
 *
 * For example, you have:
 *
 *   $frobinator = new Frobinator($a, $b, $c)
 *      ->friz()
 *      ->fraz()
 *      ->frob();
 *
 * We presume that the existing library throws an exception on error.
 *
 * You want:
 *
 *   $result = $this->taskFrobinator($a, $b, $c)
 *      ->friz()
 *      ->fraz()
 *      ->frob()
 *      ->run();
 *
 * Execution is deferred until run(), and a Robo\Result instance is
 * returned. Additionally, using Robo will covert Exceptions
 * into RoboResult objects.
 *
 * To create a new Robo task:
 *
 *  - Make a new class that extends WrapperTask
 *  - Give it a constructor that creates a new Frobinator
 *  - Override getDelegate(), and return the Frobinator instance
 *
 * Finally, add your new class to loadTasks.php as usual,
 * and you are all done.
 *
 * If you need to add any methods to your task that should run
 * immediately (e.g. to set parameters used at run() time), just
 * implement them in your derived class.
 *
 * If you need additional methods that should run deferred, just
 * define them as 'protected function _foo()'.  Then, users may
 * call $this->taskFrobinator()->foo() to get deferred execution
 * of _foo().
 */
abstract class StackBasedTask extends BaseTask
{
    protected $stack = [];

    protected $stopOnFail = true;

    public function stopOnFail($stop = true)
    {
        $this->stopOnFail = $stop;
        return $this;
    }

    /**
     * Derived classes should override the getDelegate() method, and
     * return an instance of the API class being wrapped.  When this
     * is done, any method of the delegate is available as a method of
     * this class.  Calling one of the delegate's methods will defer
     * execution until the run() method is called.
     */
    protected function getDelegate()
    {
        return null;
    }

    /**
     * Derived classes that have more than one delegate may override
     * getCommandList to add as many delegate commands as desired to
     * the list of potential functions that __call() tried to find.
     */
    protected function getDelegateCommandList($function)
    {
        return [[$this, "_$function"], [$this->getDelegate(), $function]];
    }

    /**
     * Print progress about the commands being executed
     */
    protected function printTaskProgress($command, $action)
    {
        $this->printTaskInfo("{$command[1]} " . json_encode($action));
    }

    /**
     * Derived classes can override processResult to add more
     * logic to result handling from functions. By default, it
     * is assumed that if a function returns in int, then
     * 0 == success, and any other value is the error code.
     */
    protected function processResult($function_result)
    {
        if (is_int($function_result)) {
            if ($function_result) {
                return Result::error($this, $function_result);
            }
        }
        return Result::success($this);
    }

    /**
     * Record a function to call later.
     */
    protected function addToCommandStack($command, $args)
    {
        $this->stack[] = array_merge([$command], $args);
        return $this;
    }

    /**
     * Any API function provided by the delegate that executes immediately
     * may be handled by __call automatically.  These operations will all
     * be deferred until this task's run() method is called.
     *
     * @throws \BadMethodCallException
     */
    public function __call($function, $args)
    {
        foreach ($this->getDelegateCommandList($function) as $command) {
            if (method_exists($command[0], $command[1])) {
                // If the function is a getter, call it now and
                // return the result.
                if ($this->isGetter($command[1])) {
                    return call_user_func_array($command, $args);
                }
                // If the function is a setter, call it now, and
                // return $this.
                if ($this->isSetter($command[1])) {
                    call_user_func_array($command, $args);
                    return $this;
                }
                // Otherwise, we'll defer calling this function
                // until run(), and return $this.
                $this->addToCommandStack($command, $args);
                return $this;
            }
        }

        $message = "Method $function does not exist.\n";
        throw new \BadMethodCallException($message);
    }

    protected function isGetter($function)
    {
        return substr($function, 0, 3) == "get";
    }

    protected function isSetter($function)
    {
        return substr($function, 0, 3) == "set";
    }

    /**
     * Run all of the queued objects on the stack
     */
    public function run()
    {
        $result = Result::success($this);

        foreach ($this->stack as $action) {
            $command = array_shift($action);
            $this->printTaskProgress($command, $action);
            // TODO: merge data from the result on this call
            // with data from the result on the previous call?
            // For now, the result always comes from the last function.
            $result = $this->callTaskMethod($command, $action);
            if ($this->stopOnFail && $result && !$result->wasSuccessful()) {
                break;
            }
        }

        // todo: add timing information to the result
        return $result;
    }

    /**
     * Execute one task method
     */
    protected function callTaskMethod($command, $action)
    {
        try {
            $function_result = call_user_func_array($command, $action);
            return $this->processResult($function_result);
        } catch (Exception $e) {
            $this->printTaskInfo("<error>" . $e->getMessage() . "</error>");
            return Result::error($this, $e->getMessage());
        }
    }
}
