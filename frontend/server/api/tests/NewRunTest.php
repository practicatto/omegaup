<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

require_once '../NewRun.php';
require_once '../ShowContest.php';

require_once 'NewContestTest.php';
require_once 'NewProblemInContestTest.php';

require_once 'Utils.php';


class NewRunTest extends PHPUnit_Framework_TestCase
{
    public function setUp()
    {        
        Utils::ConnectToDB();
    }
    
    public function tearDown() 
    {
        Utils::cleanup();
    }
    
    public function openContestBeforeSubmit($contest_id)
    {
        // Set context
        RequestContext::set("contest_id", $contest_id);        
        
        // Execute API
        $showContest = new ShowContest();
        try
        {
            $return_array = $showContest->ExecuteApi();
        }
        catch(ApiException $e)
        {            
            // Asume that the test is going to handle the exception
        }
        
        unset($_REQUEST["contest_id"]);
    }
    
    private function setValidContext($contest_id, $problem_id)
    {
        $this->openContestBeforeSubmit($contest_id);
        
        RequestContext::set("contest_id", $contest_id);
        RequestContext::set("problem_id", $problem_id);        
        $languages = array ('c','cpp','java','py','rb','pl','cs','p');
        RequestContext::set("language", $languages[array_rand($languages, 1)]);
        RequestContext::set("source", "#include <stdio.h> int main() { printf(\"100\"); }");
        $_SERVER['REMOTE_ADDR'] = "123.123.123.123"; 
    }
    
    public function testNewValidRun($contest_id = null, $problem_id = null)
    {
        // Login 
        $auth_token = Utils::LoginAsContestant();
        
        // Set context
        if(is_null($contest_id))
        {            
            $contestCreator = new NewContestTest();
            $contest_id = $contestCreator->testCreateValidContest(1);            
        }
        
        if(is_null($problem_id))
        {
            $problemCreator = new NewProblemInContestTest();
            $problem_id = $problemCreator->testCreateValidProblem($contest_id);
        }
        
        Utils::SetAuthToken($auth_token);
        $this->setValidContext($contest_id, $problem_id);
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            var_dump($e->getArrayMessage());            
            $this->fail("Unexpected exception");
        }
        
        // Validate output
        $this->assertEquals("ok", $return_array["status"]);
        
        // Get run from DB
        $runs = RunsDAO::search(new Runs(array('contest_id'=> $contest_id, "problem_id" => $problem_id)));
        $run = $runs[0];
        $this->assertNotNull($run);
        
        // Validate data        
        $this->assertEquals(RequestContext::get("language"), $run->getLanguage());
        $this->assertNotEmpty($run->getGuid());
        
        // Validate file created
        $filename = RUNS_PATH . $run->getGuid();
        $this->assertFileExists($filename);
        $fileContent = file_get_contents($filename);
        $this->assertEquals(RequestContext::get("source"), $fileContent);        
        
        // Validate defaults
        $this->assertEquals("new", $run->getStatus());
        $this->assertEquals(0, $run->getRuntime());
        $this->assertEquals(0, $run->getMemory());
        $this->assertEquals(0, $run->getScore());
        $this->assertEquals(0, $run->getContestScore());
        $this->assertEquals("123.123.123.123", $run->getIp());
        $this->assertEquals(0, $run->getSubmitDelay());
        $this->assertEquals("JE", $run->getVeredict());
        
    }
    
    public function testRunWhenContestExpired()
    {        
        
        // Login 
        $auth_token = Utils::LoginAsContestant();
        
        // Create public contest
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(1);
        
        // Manually expire contest
        $contest = ContestsDAO::getByPK($contest_id);                
        $contest->setFinishTime(Utils::GetTimeFromUnixTimestam(Utils::GetPhpUnixTimestamp() - 1));                        
        ContestsDAO::save($contest);
        
        // Create problem in contest        
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);
        
        // Set valid context
        Utils::SetAuthToken($auth_token);
        $this->setValidContext($contest_id, $problem_id);
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            // Validate exception            
            $exception_message = $e->getArrayMessage();            
            $this->assertEquals("User is not allowed to view this content.", $exception_message["error"]);
            $this->assertEquals("error", $exception_message["status"]);
            $this->assertEquals("HTTP/1.1 403 FORBIDDEN", $exception_message["header"]);                         
            
            // We're OK
            return;            
        }
        
        var_dump($contest);
        var_dump($return_array);
        $this->fail("Contestant was able to submit run in an expired contest.");
        
    }
    
    public function testRunToValidPrivateContest()
    {                
        // Set context
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(0);
        
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);        
        
        // Login 
        $auth_token = Utils::LoginAsContestDirector();        
        Utils::SetAuthToken($auth_token);
        
        $this->setValidContext($contest_id, $problem_id);
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            var_dump($e->getArrayMessage());            
            $this->fail("Unexpected exception");
        }
        
        // Validate output
        $this->assertEquals("ok", $return_array["status"]);
        
        // Get run from DB
        $runs = RunsDAO::search(new Runs(array('contest_id'=> $contest_id, "problem_id" => $problem_id)));
        $run = $runs[0];
        $this->assertNotNull($run);
        
        // Validate data        
        $this->assertEquals(RequestContext::get("language"), $run->getLanguage());
        $this->assertNotEmpty($run->getGuid());
        
        // Validate file created
        $filename = RUNS_PATH . $run->getGuid();
        $this->assertFileExists($filename);
        $fileContent = file_get_contents($filename);
        $this->assertEquals(RequestContext::get("source"), $fileContent);        
        
        // Validate defaults
        $this->assertEquals("new", $run->getStatus());
        $this->assertEquals(0, $run->getRuntime());
        $this->assertEquals(0, $run->getMemory());
        $this->assertEquals(0, $run->getScore());
        $this->assertEquals(0, $run->getContestScore());
        $this->assertEquals("123.123.123.123", $run->getIp());
        $this->assertEquals(0, $run->getSubmitDelay());
        $this->assertEquals("JE", $run->getVeredict());
    }
    
    public function testRunToInvalidPrivateContest()
    {                
        // Set context
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(0);
        
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);        
        
        // Login 
        $auth_token = Utils::LoginAsContestant2();        
        Utils::SetAuthToken($auth_token);
        
        $this->setValidContext($contest_id, $problem_id);
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            // Validate exception            
            $exception_message = $e->getArrayMessage();            
            $this->assertEquals("User is not allowed to view this content.", $exception_message["error"]);
            $this->assertEquals("error", $exception_message["status"]);
            $this->assertEquals("HTTP/1.1 403 FORBIDDEN", $exception_message["header"]);                         
            
            // We're OK
            return;            
        }
        
        var_dump($contest);
        var_dump($return_array);
        $this->fail("Contestant was able to submit run in a private contest and was not invited!.");
    }
    
    
    public function testRunWhenContestNotStarted()
    {        
        
        // Login 
        $auth_token = Utils::LoginAsContestant();
        
        // Create public contest
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(1);
        
        // Manually expire contest
        $contest = ContestsDAO::getByPK($contest_id);                
        $contest->setStartTime(Utils::GetTimeFromUnixTimestam(Utils::GetPhpUnixTimestamp() + 1));                        
        ContestsDAO::save($contest);
        
        // Create problem in contest        
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);
        
        // Set valid context
        Utils::SetAuthToken($auth_token);
        
        $this->setValidContext($contest_id, $problem_id);
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            // Validate exception            
            $exception_message = $e->getArrayMessage();            
            $this->assertEquals("User is not allowed to view this content.", $exception_message["error"]);
            $this->assertEquals("error", $exception_message["status"]);
            $this->assertEquals("HTTP/1.1 403 FORBIDDEN", $exception_message["header"]);                         
            
            // We're OK
            return;            
        }
        
        var_dump($contest);
        var_dump($return_array);
        $this->fail("Contestant was able to submit run in an not yet started contest.");
        
    }
    
    public function testInvalidRunInsideSubmissionsGap()
    {
        // Login 
        $auth_token = Utils::LoginAsContestant();
        
        // Create public contest
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(1);   
        
        // Set submissions gap of 2 seconds
        $contest = ContestsDAO::getByPK($contest_id);                
        $contest->setSubmissionsGap(2);
        ContestsDAO::save($contest);
        
        // Create problem in contest        
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);
        
        // Set valid context for Run 
        Utils::SetAuthToken($auth_token);
        $this->setValidContext($contest_id, $problem_id);
        
        $newRun = new NewRun();        
        for($i = 0; $i < 2; $i++)
        {
            // Send first Run, should succeed
            sleep(1);
            try
            {
                $return_array = $newRun->ExecuteApi();
            }
            catch(ApiException $e)
            {                
                var_dump($e->getArrayMessage());            
                $this->fail("Unexpected exception");
            }

            // Validate output
            $this->assertEquals("ok", $return_array["status"]);

            // Send second run after 1 sec, should be invalid
            sleep(1);
            try
            {
                $return_array = $newRun->ExecuteApi();
            }
            catch(ApiException $e)
            {
                // Validate exception            
                $exception_message = $e->getArrayMessage();            
                $this->assertEquals("You're not allowed to submit yet.", $exception_message["error"]);
                $this->assertEquals("error", $exception_message["status"]);
                $this->assertEquals("HTTP/1.1 401 FORBIDDEN", $exception_message["header"]);                                         
                
                // We're OK
                continue;
            }
            var_dump($contest);
            var_dump($return_array);
            $this->fail("Contestant was able to submit run inside the submission gap.");
        }
    }
            
    public function testSubmissionGapIsPerProblem()
    {
        // Login 
        $auth_token = Utils::LoginAsContestant();
        
        // Create public contest
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(1);   
        
        // Set submissions gap of 2 seconds
        $contest = ContestsDAO::getByPK($contest_id);                
        $contest->setSubmissionsGap(2);
        ContestsDAO::save($contest);
        
        // Create 3 problems in contest        
        $problemCreator = new NewProblemInContestTest();
        $problem_id[0] = $problemCreator->testCreateValidProblem($contest_id);
        $problem_id[1] = $problemCreator->testCreateValidProblem($contest_id);
        $problem_id[2] = $problemCreator->testCreateValidProblem($contest_id);
        
        // Set valid context for Run 
        Utils::SetAuthToken($auth_token);
        $this->setValidContext($contest_id, $problem_id);
        
        $newRun = new NewRun();        
        
        // Send problems
        for($i = 0; $i < 3; $i++)
        {
            // Try different problem id
            RequestContext::set("problem_id", $problem_id[$i]);        
            
            try
            {
                $return_array = $newRun->ExecuteApi();
            }
            catch(ApiException $e)
            {                
                var_dump($e->getArrayMessage());            
                $this->fail("Unexpected exception");
            }

            // Validate output
            $this->assertEquals("ok", $return_array["status"]);
        }
        
    }
    
    public function testInvalidContestProblemCombination()
    {        
        
        // Login 
        $auth_token = Utils::LoginAsContestant();                
        
        // Create public contest 1
        $contestCreator = new NewContestTest();
        $contest_id_1 = $contestCreator->testCreateValidContest(1);   
        
        // Create public contest 2
        $contestCreator = new NewContestTest();
        $contest_id_2 = $contestCreator->testCreateValidContest(1);   
        
        // Create problem in contest 2       
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id_2);
        
        // Set invalid context
        Utils::SetAuthToken($auth_token);        
        $this->setValidContext($contest_id_1, $problem_id);        
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            // Validate exception            
            $exception_message = $e->getArrayMessage();            
            $this->assertEquals("problem_id and contest_id combination is invalid.", $exception_message["error"]);
            $this->assertEquals("error", $exception_message["status"]);
            $this->assertEquals("HTTP/1.1 400 BAD REQUEST", $exception_message["header"]);                         
            
            // We're OK
            return;            
        }
        
        var_dump($contest);
        var_dump($return_array);
        $this->fail("Contestant was able to submit run in an not yet started contest.");
        
    }
            
    public function testMissingParameters()
    {
        // Set context
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(1);
        
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);        
        
        // Login 
        $auth_token = Utils::LoginAsContestDirector();        
        Utils::SetAuthToken($auth_token);
        
        $this->setValidContext($contest_id, $problem_id);
        
        $needed_keys = array(
            "problem_id",
            "contest_id",
            "language",
            "source"                        
        );
        
        foreach($needed_keys as $key)        
        {
            // Reset context
            Utils::SetAuthToken($auth_token);
            $this->setValidContext($contest_id, $problem_id);
            
            // Unset key
            unset($_REQUEST[$key]);
            
            // Execute API
            $newRun = new NewRun();
            try
            {
                $return_array = $newRun->ExecuteApi();
            }
            catch(ApiException $e)
            {
                // Exception is expected
                $exception_array = $e->getArrayMessage();            

                // Validate exception
                $this->assertNotNull($exception_array);
                $this->assertArrayHasKey('error', $exception_array);                    
                
                // We're OK
                continue;
            }
            
            $this->fail("Exception was expected. Parameter: ". $key);            
        }
    }
    
    public function testNewRunInUsacoPublicContest()
    {
        // Login 
        $auth_token = Utils::LoginAsContestant();
        
        // Set context        
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(1);        
               
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);        
        
        // Alter Contest window length
        $contest = ContestsDAO::getByPK($contest_id);
        $contest->setWindowLength(20);
        ContestsDAO::save($contest);
        
        Utils::SetAuthToken($auth_token);
        $this->setValidContext($contest_id, $problem_id);
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            var_dump($e->getArrayMessage());            
            $this->fail("Unexpected exception");
        }
        
        // Validate output
        $this->assertEquals("ok", $return_array["status"]);
    }
    
    public function testNewRunOutUsacoPublicContest()
    {
        // Login 
        $auth_token = Utils::LoginAsContestant();
        
        // Set context        
        $contestCreator = new NewContestTest();
        $contest_id = $contestCreator->testCreateValidContest(1);        
               
        $problemCreator = new NewProblemInContestTest();
        $problem_id = $problemCreator->testCreateValidProblem($contest_id);        
        
        // Alter Contest window length
        $contest = ContestsDAO::getByPK($contest_id);
        $contest->setWindowLength(20);
        ContestsDAO::save($contest);                
        
        Utils::SetAuthToken($auth_token);
        $this->setValidContext($contest_id, $problem_id);
        
        // Alter first access time to make appear the run outside window length
        $contest_user = ContestsUsersDAO::getByPK(Utils::GetContestantUserId(), $contest_id);
        $contest_user->setAccessTime(date("Y-m-d H:i:s", time() - 21));
        ContestsUsersDAO::save($contest_user);
        
        // Execute API
        $newRun = new NewRun();
        try
        {
            $return_array = $newRun->ExecuteApi();
        }
        catch(ApiException $e)
        {
            // Validate exception            
            $exception_message = $e->getArrayMessage();            
            $this->assertEquals("User is not allowed to view this content.", $exception_message["error"]);
            $this->assertEquals("error", $exception_message["status"]);
            $this->assertEquals("HTTP/1.1 403 FORBIDDEN", $exception_message["header"]);                         
            
            // We're OK
            return;            
        }
        
        var_dump($contest);
        var_dump($return_array);
        $this->fail("Contestant was able to submit run in an expired contest.");
    }               
}