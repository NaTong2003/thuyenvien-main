<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Test;
use App\Models\Question;
use App\Models\TestQuestion;
use App\Models\TestAttempt;
use App\Models\Position;
use App\Models\ShipType;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TestController extends Controller
{
    /**
     * Hiển thị danh sách bài kiểm tra
     */
    public function index(Request $request)
    {
        $query = Test::with('position', 'shipType');

        // Lọc theo chức danh
        if ($request->has('position_id') && !empty($request->position_id)) {
      // Kiểm tra xem request có gửi lên position_id hay không 
    // và giá trị position_id có khác rỗng hay không
    // Nếu có, thêm điều kiện lọc theo position_id vào query
            $query->where('position_id', $request->position_id);
        }
        //lọc theo trạng thái
        if ($request->has('is_active') && $request->is_active !== '') {
        $query->where('is_active', $request->is_active);
}

        // Lọc theo loại tàu
        if ($request->has('ship_type_id') && !empty($request->ship_type_id)) {
            $query->where('ship_type_id', $request->ship_type_id);
        }
        //lọc theo độ khó
    if ($request->has('difficulty') && !empty($request->difficulty)) {
    $query->where('difficulty', $request->difficulty);
    }
        // Lọc theo từ khóa
        if ($request->has('search') && !empty($request->search)) {
            $query->where('title', 'like', '%' . $request->search . '%')
                ->orWhere('description', 'like', '%' . $request->search . '%');
        }
        // Lọc theo loại bài kiểm tra
       if ($request->has('type') && !empty($request->type)) {
        $query->where('type', $request->type);
}

        $tests = $query->orderBy('created_at', 'desc')->paginate(10);
        $positions = Position::all();
        $shipTypes = ShipType::all();

        return view('admin.tests.index', compact('tests', 'positions', 'shipTypes'));
    }

    /**
     * Hiển thị form tạo bài kiểm tra mới
     */
    public function create()
    {
        $positions = Position::all();
        $shipTypes = ShipType::all();
        $questions = Question::with('position', 'shipType')
            ->orderBy('category')
            ->orderBy('difficulty')
            ->get();

        return view('admin.tests.create', compact('positions', 'shipTypes', 'questions'));
    }

    /**
     * Lưu bài kiểm tra mới vào database
     */
    public function store(Request $request)
    {
        // Ghi log request data để debug
        Log::info('Test store request data: ' . json_encode($request->all()));

        // Xác thực dữ liệu - không sử dụng try-catch để Laravel tự động redirect với lỗi
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'duration' => 'required|integer|min:5|max:180',
            'passing_score' => 'required|integer|min:0|max:100',
            'position_id' => 'nullable|exists:positions,id',
            'ship_type_id' => 'nullable|exists:ship_types,id',
            'question_ids' => $request->has('is_random') ? 'nullable|array' : 'required|array|min:1',
            'question_ids.*' => 'exists:questions,id',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'nullable|boolean',
            'is_random' => 'nullable|boolean',
            'random_questions_count' => 'required_if:is_random,1|nullable|integer|min:1',
            'difficulty' => 'required|string',
            'type' => 'required|string',
            // Thêm validation cho các cài đặt bài kiểm tra
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_answers' => 'nullable|boolean',
            'allow_back' => 'nullable|boolean',
            'show_result_immediately' => 'nullable|boolean',
            'max_attempts' => 'nullable|integer|min:0',
        ]);

        DB::beginTransaction();

        try {
            // Tạo bài kiểm tra mới
            $test = Test::create([
                'title' => $request->title,
                'description' => $request->description,
                'duration' => $request->duration,
                'passing_score' => $request->passing_score,
                'position_id' => $request->position_id,
                'ship_type_id' => $request->ship_type_id,
                'category_id' => $request->category_id,
                'is_active' => $request->has('is_active') ? true : false,
                'is_random' => $request->has('is_random') ? true : false,
                'difficulty' => $request->difficulty,
                'type' => $request->type,
                'created_by' => auth()->id(),
            ]);

            // Tạo cài đặt cho bài kiểm tra
            $test->settings()->create([
                'shuffle_questions' => $request->has('shuffle_questions'),
                'shuffle_answers' => $request->has('shuffle_answers'),
                'allow_back' => $request->has('allow_back'),
                'show_result_immediately' => $request->has('show_result_immediately'),
                'max_attempts' => $request->max_attempts ?? null,
            ]);

            // Nếu không phải bài kiểm tra ngẫu nhiên, thêm các câu hỏi cố định
            if (!$request->has('is_random')) {//kiểm tra xem request có gửi lên tham số is_random hay không
                if (empty($request->question_ids)) {
            //Nếu request KHÔNG có danh sách question_ids hoặc danh sách đó RỖNG, thì thực hiện đoạn code bên trong
                    DB::rollBack();
                    return redirect()->back()->withErrors(['question_ids' => 'Vui lòng chọn ít nhất 1 câu hỏi cho bài kiểm tra'])->withInput();
                }

                foreach ($request->question_ids as $index => $question_id) {
        //// Duyệt qua từng ID câu hỏi trong mảng question_ids,
    // lấy số thứ tự ($index) và giá trị ID câu hỏi ($question_id),
    // và lưu vào bảng TestQuestion.
                    TestQuestion::create([
                        'test_id' => $test->id,//// Gán test_id là ID của bài test hiện tại
                        'question_id' => $question_id,
                        'order' => $index + 1,
                    ]);
                }
            } else {
                // Lưu thông tin về số lượng câu hỏi ngẫu nhiên vào bảng cài đặt
                // hoặc một trường metadata của bài kiểm tra nếu có
                $test->random_questions_count = $request->random_questions_count;
                $test->save();

                $questions = Question::with('position', 'shipType')
                    ->where('difficulty', $request->difficulty)
        //Lọc những câu hỏi có độ khó (difficulty) đúng với giá trị được chọn từ request
                    ->orderBy('category')
                    ->orderBy('difficulty')
                    ->get();

                $availableQuestionsCount = $questions->count();
                if ($availableQuestionsCount < $request->random_questions_count) {
       // Nếu số câu hỏi hiện có < số câu hỏi yêu cầu lấy ngẫu nhiên → thì thực hiện đoạn code bên trong
                    DB::rollBack();
                    return redirect()->back()
                        ->with('error', "Không đủ câu hỏi thỏa mãn điều kiện. Chỉ có {$availableQuestionsCount} câu hỏi khả dụng.")
                        ->withInput();
                }

                $randomQuestions = $questions->random($test->random_questions_count);
//Lấy ngẫu nhiên số câu hỏi theo yêu cầu từ tập câu hỏi hiện có
                foreach ($randomQuestions as $index => $question) {
//Duyệt qua từng câu hỏi trong tập $randomQuestions, lấy cả chỉ số thứ tự ($index) và nội dung câu hỏi ($question).
                    TestQuestion::create([
                        'test_id' => $test->id,
                        'question_id' => $question->id,
                        'order' => $index + 1,
                    ]);
                }
            }

            DB::commit();

            return redirect()->route('admin.tests.index')
                ->with('success', 'Thêm bài kiểm tra thành công!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating test: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Đã xảy ra lỗi khi thêm bài kiểm tra: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Hiển thị thông tin chi tiết bài kiểm tra
     */
    public function show($id)
    {
        $test = Test::with(['position', 'shipType', 'testAttempts.user'])
            ->findOrFail($id);
//Lấy thông tin bài test qua dữ liệu các bảng vị trí, loại tàu và các lần làm bài (kèm user), theo ID
        // Sắp xếp các câu hỏi theo thứ tự
        $testQuestions = TestQuestion::where('test_id', $test->id)->with('question')->orderBy('order')->get();
//Lọc các dòng trong bảng TestQuestion theo ID bài test.Lấy kèm thông tin chi tiết của từng câu hỏi từ bảng Question.
        // Tính toán thống kê
        $stats = [];
        $testAttempts = $test->testAttempts;

        if ($testAttempts->count() > 0) {
            // Tổng số lượt làm bài
            $stats['totalAttempts'] = $testAttempts->count();
//Kiểm tra nếu bài test có ít nhất 1 lần làm bài, thì tính tổng số lượt làm bài và lưu vào stats['totalAttempts'].
            // Điểm trung bình
            $stats['avgScore'] = $testAttempts->avg('score');

            // Điểm cao nhất và thấp nhất
            $stats['highestScore'] = $testAttempts->max('score');
            $stats['lowestScore'] = $testAttempts->min('score');

            // Số lượt đạt và tỷ lệ đạt
            $passingScore = $test->passing_score;
            $passCount = $testAttempts->filter(function ($attempt) use ($passingScore) {
                return $attempt->score >= $passingScore;
            })->count();
//Lọc danh sách các lần làm bài để giữ lại các lần có điểm ≥ điểm đạt chuẩn, sau đó đếm số lần đạt và lưu vào biến $passCount
            $stats['passCount'] = $passCount;
            $stats['failCount'] = $stats['totalAttempts'] - $passCount;
            $stats['passRate'] = ($passCount / $stats['totalAttempts']) * 100;
// Lưu số lượt đạt, số lượt không đạt, và tỷ lệ % đạt điểm chuẩn vào mảng thống kê.
            // Tạo dữ liệu cho biểu đồ phân phối điểm số
            $scoreRanges = [
                '0-10', '11-20', '21-30', '31-40', '41-50',
                '51-60', '61-70', '71-80', '81-90', '91-100'
            ];

            $scoreDistribution = array_fill(0, count($scoreRanges), 0);
//Khởi tạo mảng $scoreDistribution để đếm số lượt làm bài theo từng khoảng điểm, với các giá trị ban đầu bằng 0.
            foreach ($testAttempts as $attempt) {//Duyệt qua từng lần làm bài trong danh sách để xử lý dữ liệu.
                $score = $attempt->score;//Lấy điểm số của lần làm bài hiện tại
                $index = min(floor($score / 10), 9); // Đảm bảo điểm số 100 nằm trong khoảng 91-100
                $scoreDistribution[$index]++;
            }
//Cộng thêm 1 cho khoảng điểm tương ứng với điểm của lần làm bài này.
            $stats['scoreLabels'] = $scoreRanges;
            $stats['scoreDistribution'] = $scoreDistribution;
        }
//Lưu danh sách khoảng điểm và số lượt làm bài theo từng khoảng điểm vào mảng thống kê 
        return view('admin.tests.show', compact('test', 'testQuestions', 'stats'));
    }

    /**
     * Hiển thị form chỉnh sửa bài kiểm tra
     */
    public function edit($id)
    {
        $test = Test::with('questions')->findOrFail($id);//Test lấy thông tin từ bảng question thông qua id
        $positions = Position::all();
        $shipTypes = ShipType::all();

        // Lấy danh sách câu hỏi
        $questions = Question::with('position', 'shipType')
            ->orderBy('category')
            ->orderBy('difficulty')
            ->get();
//Lấy danh sách tất cả câu hỏi, kèm thông tin vị trí và loại tàu, sắp xếp theo chuyên mục và độ khó.
        // Lấy các ID câu hỏi hiện đang có trong bài kiểm tra
        $selectedQuestionIds = $test->questions()->pluck('question_id')->toArray();
// Truy cập bảng liên kết giữa bài test và câu hỏi. Lấy danh sách ID câu hỏi của bài test.Chuyển danh sách ID từ Collection thành mảng PHP.
        return view('admin.tests.edit', compact('test', 'positions', 'shipTypes', 'questions', 'selectedQuestionIds'));
    }

    /**
     * Cập nhật thông tin bài kiểm tra
     */
    public function update(Request $request, $id)
    {
        $test = Test::findOrFail($id);

        try {
            // Ghi log request data để debug
            Log::info('Test update request data: ' . json_encode($request->all()));

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'duration' => 'required|integer|min:5|max:180',
                'passing_score' => 'required|integer|min:0|max:100',
                'position_id' => 'nullable|exists:positions,id',
                'ship_type_id' => 'nullable|exists:ship_types,id',
                'question_ids' => $request->has('is_random') ? 'nullable|array' : 'required|array|min:1',
                'question_ids.*' => 'exists:questions,id',
                'category_id' => 'required|exists:categories,id',
                'is_active' => 'nullable|boolean',
                'is_random' => 'nullable|boolean',
                'random_questions_count' => 'required_if:is_random,1|nullable|integer|min:1',
                'difficulty' => 'required|string',
                'type' => 'required|string',
                // Thêm validation cho các cài đặt bài kiểm tra
                'shuffle_questions' => 'nullable|boolean',
                'shuffle_answers' => 'nullable|boolean',
                'allow_back' => 'nullable|boolean',
                'show_result_immediately' => 'nullable|boolean',
                'max_attempts' => 'nullable|integer|min:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Ghi log lỗi validation
            Log::error('Test update validation errors: ' . json_encode($e->errors()));
            return redirect()->back()->withErrors($e->validator)->withInput();
        }
//Ghi log lỗi validation khi cập nhật bài test, sau đó quay về form, hiển thị lỗi và giữ lại dữ liệu đã nhập.
        DB::beginTransaction();

        try {
            // Cập nhật thông tin bài kiểm tra
            $test->update([
                'title' => $request->title,
                'description' => $request->description,
                'duration' => $request->duration,
                'passing_score' => $request->passing_score,
                'position_id' => $request->position_id,
                'ship_type_id' => $request->ship_type_id,
                'category_id' => $request->category_id,
                'is_active' => $request->has('is_active') ? true : false,
                'is_random' => $request->has('is_random') ? true : false,
                'difficulty' => $request->difficulty,
                'type' => $request->type,
            ]);

            // Cập nhật hoặc tạo mới cài đặt bài kiểm tra
            $test->settings()->updateOrCreate(//Cập nhật hoặc tạo mới cấu hình của bài test, dựa trên test_id.
                ['test_id' => $test->id],
                [
                    'shuffle_questions' => $request->has('shuffle_questions'),
                    'shuffle_answers' => $request->has('shuffle_answers'),
                    'allow_back' => $request->has('allow_back'),
                    'show_result_immediately' => $request->has('show_result_immediately'),
                    'max_attempts' => $request->max_attempts ?? null,
                ]
            );

            // Nếu không phải bài kiểm tra ngẫu nhiên, thêm các câu hỏi cố định
            if (!$request->has('is_random')) {//Kiểm tra nếu request KHÔNG có trường is_random, thì thực hiện các xử lý tiếp theo.
                // Xóa tất cả câu hỏi cũ
                TestQuestion::where('test_id', $test->id)->delete();

                if ($request->has('question_ids') && is_array($request->question_ids)) {
    // Kiểm tra nếu request có trường question_ids và trường này là một mảng, thì thực hiện xử lý tiếp theo.
                    foreach ($request->question_ids as $index => $question_id) {
    //Duyệt qua từng ID câu hỏi mà người dùng đã chọn, đồng thời lấy luôn vị trí của mỗi câu hỏi trong danh sách, 
    //để sau đó lưu vào bảng test_questions với đúng thứ tự mong muốn trong bài test.
                        $testQuestion = new TestQuestion();

                        $testQuestion->test_id = $test->id;
                        $testQuestion->question_id = $question_id;
                        $testQuestion->order = $index + 1;
                        $ok = $testQuestion->save();
                    }

                } else {
                    // Log lỗi nếu không có câu hỏi được chọn
                    Log::error('Không có câu hỏi nào được chọn trong bài kiểm tra ID: ' . $test->id);
                    throw new \Exception('Bạn cần chọn ít nhất một câu hỏi cho bài kiểm tra này.');
                }
            } else {
                if ($test->random_questions_count != $request->random_questions_count) {
    //Kiểm tra nếu số lượng câu hỏi ngẫu nhiên trong bài test hiện tại khác với số lượng người dùng vừa nhập, thì thực hiện cập nhật.
                    // Xóa tất cả câu hỏi cũ
                    TestQuestion::where('test_id', $test->id)->delete();

                    // Lưu thông tin về số lượng câu hỏi ngẫu nhiên
                    $test->random_questions_count = $request->random_questions_count;
                    $test->save();

                    $questions = Question::with('position', 'shipType')
                        ->where('difficulty', $request->difficulty)
                        ->orderBy('category')
                        ->orderBy('difficulty')
                        ->get();

                    $availableQuestionsCount = $questions->count();
                    if ($availableQuestionsCount < $request->random_questions_count) {
    // Kiểm tra nếu số lượng câu hỏi có sẵn ít hơn số câu hỏi ngẫu nhiên mà người dùng yêu cầu, thì xử lý cảnh báo hoặc báo lỗi.
                        DB::rollBack();
                        return redirect()->back()
                            ->with('error', "Không đủ câu hỏi thỏa mãn điều kiện. Chỉ có {$availableQuestionsCount} câu hỏi khả dụng.")
                            ->withInput();
                    }


                    $randomQuestions = $questions->random($test->random_questions_count);
// Chọn ngẫu nhiên số lượng câu hỏi đúng bằng số câu hỏi ngẫu nhiên mà bài test yêu cầu.
                    foreach ($randomQuestions as $index => $question) {
                        TestQuestion::create([
                            'test_id' => $test->id,
                            'question_id' => $question->id,
                            'order' => $index + 1,
                        ]);
                    }
                }
            }
//Duyệt qua từng câu hỏi đã chọn ngẫu nhiên, và lưu vào bảng test_questions với ID bài test, ID câu hỏi và thứ tự hiển thị trong bài test.
            DB::commit();

            return redirect()->route('admin.tests.index')
                ->with('success', 'Cập nhật bài kiểm tra thành công!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Đã xảy ra lỗi khi cập nhật bài kiểm tra: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Xóa bài kiểm tra
     */
    public function destroy($id)
    {
        $test = Test::findOrFail($id);

        // Kiểm tra xem bài kiểm tra đã có ai làm chưa
        if ($test->testAttempts()->count() > 0) {
            // Kiểm tra xem có ai làm xong bài chưa
            $completedAttempts = $test->testAttempts()->where('is_completed', true)->count();
// Đếm số lượt làm bài đã hoàn thành của bài test.

            if ($completedAttempts > 0) {
                return redirect()->route('admin.tests.index')
                    ->with('error', 'Không thể xóa bài kiểm tra này vì đã có thuyền viên hoàn thành bài!');
            }

            // Nếu chỉ có các lượt thử chưa hoàn thành, xóa các lượt thử này
            $test->testAttempts()->where('is_completed', false)->delete();// Xoá tất cả các lần làm bài chưa hoàn thành của bài test.
            Log::info('Đã xóa ' . ($test->testAttempts()->count() - $completedAttempts) . ' lượt thử chưa hoàn thành của bài kiểm tra ID: ' . $test->id);
        }
//Số lượt làm bài chưa hoàn thành = tổng số sau khi xoá - số lượt đã hoàn thành (vì mình chỉ giữ lại các lượt hoàn thành).
        // Xóa tất cả câu hỏi của bài kiểm tra
        $test->questions()->delete();

        // Xóa bài kiểm tra
        $test->delete();

        return redirect()->route('admin.tests.index')
            ->with('success', 'Xóa bài kiểm tra thành công!');
    }

    /**
     * Hiển thị kết quả của bài kiểm tra
     */
    public function results($id)
    {
        $test = Test::with('position', 'shipType')->findOrFail($id);
        $testAttempts = TestAttempt::with('user')
            ->where('test_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('admin.tests.results', compact('test', 'testAttempts'));
    }

    /**
     * Hiển thị form tạo bài kiểm tra ngẫu nhiên
     */
    public function createRandom()
    {
        $positions = Position::all();
        $shipTypes = ShipType::all();
        $categories = Category::all();

        return view('admin.tests.create_random', compact('positions', 'shipTypes', 'categories'));
    }

    /**
     * Lưu bài kiểm tra ngẫu nhiên vào database
     */
    public function storeRandom(Request $request)
    {
        // Ghi log request data để debug
        Log::info('Random test store request data: ' . json_encode($request->all()));

        // Xác thực dữ liệu - không sử dụng try-catch để Laravel tự động redirect với lỗi
        DB::beginTransaction();

        try {
            // Tạo bài kiểm tra ngẫu nhiên mới
            $test = Test::create([
                'title' => $request->title,
                'description' => $request->description,
                'duration' => $request->duration,
                'passing_score' => $request->passing_score,
                'position_id' => $request->position_id,
                'ship_type_id' => $request->ship_type_id,
                'category_id' => $request->category_id,
                'difficulty' => $request->difficulty,
                'type' => $request->type,
                'is_active' => $request->has('is_active') ? true : false,
                'is_random' => true,
                'random_questions_count' => $request->random_questions_count,
                'created_by' => auth()->id(),
            ]);

            // Chọn câu hỏi ngẫu nhiên dựa trên các tiêu chí
            $query = Question::query();
//Khởi tạo một query trống cho bảng questions, để tiếp tục xây dựng các điều kiện truy vấn sau.
            // Lọc theo chức danh
            if ($request->position_id) {
                $query->where(function ($q) use ($request) {
                    $q->where('position_id', $request->position_id)
                        ->orWhereNull('position_id');
                });
            }
// Kiểm tra xem trong request có position_id không (người dùng có chọn lọc theo vị trí không).
// Lấy các câu hỏi có position_id đúng với vị trí người dùng chọn
//Hoặc lấy các câu hỏi mà chưa gán vị trí → position_id IS NULL.
            // Lọc theo loại tàu
            if ($request->ship_type_id) {
                $query->where(function ($q) use ($request) {
                    $q->where('ship_type_id', $request->ship_type_id)
                        ->orWhereNull('ship_type_id');
                });
            }

            // Lọc theo độ khó
            if ($request->difficulty && $request->difficulty != '-- Tất cả độ khó --') {
                $query->where('difficulty', $request->difficulty);
            }
//kiểm tra người dùng có gửi giá trị difficulty không.
//Đảm bảo giá trị không phải là lựa chọn mặc định kiểu “Tất cả độ khó”.
//Chỉ lấy các câu hỏi có trường difficulty đúng với độ khó mà người dùng chọn
            // Lọc theo danh mục
            if ($request->category_id && $request->category_id != '-- Tất cả danh mục --') {
                $query->where(function ($q) use ($request) {
                    $q->where('category_id', $request->category_id);
                });
            }

            // Đếm số lượng câu hỏi thỏa mãn điều kiện
            $availableQuestionsCount = $query->count();
// Đếm tổng số câu hỏi thỏa các điều kiện lọc đã áp dụng trên $query.
            // Kiểm tra nếu không có đủ câu hỏi
            if ($availableQuestionsCount < $request->random_questions_count) {
//Kiểm tra xem số lượng câu hỏi có sẵn sau khi lọc có đủ để lấy số lượng câu hỏi ngẫu nhiên mà người dùng yêu cầu không.
                DB::rollBack();
                return redirect()->back()
                    ->with('error', "Không đủ câu hỏi thỏa mãn điều kiện. Chỉ có {$availableQuestionsCount} câu hỏi khả dụng.")
                    ->withInput();
            }

            // Lưu các cài đặt bài kiểm tra
            $test->settings()->create([
                'shuffle_questions' => true, // Mặc định bật xáo trộn câu hỏi cho bài kiểm tra ngẫu nhiên
                'shuffle_answers' => $request->has('shuffle_answers'),
                'allow_back' => $request->has('allow_back'),
                'show_result_immediately' => $request->has('show_result_immediately'),
                'max_attempts' => $request->max_attempts ?? null,
            ]);

            $test->random_questions_count = $request->random_questions_count;
            $test->save();

            $questions = $query->get();

            $randomQuestions = $questions->random($test->random_questions_count);
//Chọn ngẫu nhiên số lượng câu hỏi đúng bằng số câu hỏi ngẫu nhiên mà bài test yêu cầu.
            foreach ($randomQuestions as $index => $question) {//Duyệt qua từng câu hỏi ngẫu nhiên đã chọn, đồng thời lấy vị trí (thứ tự) của từng câu hỏi trong danh sách.
                TestQuestion::create([
                    'test_id' => $test->id,
                    'question_id' => $question->id,
                    'order' => $index + 1,
                ]);
            }
//Duyệt qua từng câu hỏi ngẫu nhiên đã chọn, và lưu vào bảng test_questions với ID bài test, ID câu hỏi và thứ tự hiển thị trong bài test.
            DB::commit();

            return redirect()->route('admin.tests.index')
                ->with('success', 'Thêm bài kiểm tra ngẫu nhiên thành công!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating random test: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Đã xảy ra lỗi khi thêm bài kiểm tra ngẫu nhiên: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Xem trước bài kiểm tra
     */
    public function preview($id)
    {
        $test = Test::findOrFail($id);

        // Sắp xếp các câu hỏi theo thứ tự
        $testQuestions = TestQuestion::where('test_id', $id)
            ->with('question.answers')
            ->orderBy('order')
            ->get();

        return view('admin.tests.preview', compact('test', 'testQuestions'));
    }

    /**
     * Hiển thị thống kê chi tiết của bài kiểm tra
     */
    public function statistics($id)
    {
        $test = Test::with(['testAttempts.user', 'testAttempts.userResponses'])
            ->findOrFail($id);

        $testAttempts = $test->testAttempts()->with('user', 'userResponses.question', 'userResponses.answer')
            ->orderBy('created_at', 'desc')
            ->get();

        // Tính toán thống kê chi tiết
        $stats = [];

        if ($testAttempts->count() > 0) {
            // Tổng số lượt làm bài
            $stats['totalAttempts'] = $testAttempts->count();

            // Điểm trung bình
            $stats['avgScore'] = $testAttempts->avg('score');

            // Điểm cao nhất và thấp nhất
            $stats['highestScore'] = $testAttempts->max('score');
            $stats['lowestScore'] = $testAttempts->min('score');

            // Số lượt đạt và tỷ lệ đạt
            $passingScore = $test->passing_score;//Lấy điểm đạt yêu cầu của bài test → gán vào $passingScore
            $passCount = $testAttempts->filter(function ($attempt) use ($passingScore) {//Duyệt qua tất cả attempts (lượt làm bài).
                return $attempt->score >= $passingScore;//Duyệt qua tất cả attempts (lượt làm bài).
                //Giữ lại những lượt có score >= passingScore → tức là những người đậu.
            })->count();
// Đếm số lượt làm bài mà đạt điểm qua bài test, lưu vào $passCount.
            $stats['passCount'] = $passCount;
            $stats['failCount'] = $stats['totalAttempts'] - $passCount;
            $stats['passRate'] = ($passCount / $stats['totalAttempts']) * 100;

            // Tạo dữ liệu cho biểu đồ phân phối điểm số
            $scoreRanges = [
                '0-10', '11-20', '21-30', '31-40', '41-50',
                '51-60', '61-70', '71-80', '81-90', '91-100'
            ];

            $scoreDistribution = array_fill(0, count($scoreRanges), 0);
//tạo biến để đếm
            foreach ($testAttempts as $attempt) {
                $score = $attempt->score;
                $index = min(floor($score / 10), 9); // Đảm bảo điểm số 100 nằm trong khoảng 91-100
                $scoreDistribution[$index]++;
            }

            $stats['scoreLabels'] = $scoreRanges;
            $stats['scoreDistribution'] = $scoreDistribution;
//ghi nhận số bài nằm trong khoảng điểm
            // Thống kê theo thời gian
            $timeData = [];
            $dateLabels = [];

            // Lấy dữ liệu trong 30 ngày gần nhất
            for ($i = 0; $i < 30; $i++) {
//Đây là vòng lặp for chạy từ $i = 0 đến $i = 29 (tổng cộng 30 lần lặp).

//Mỗi lần lặp, giá trị của $i sẽ tăng lên 1
                $date = now()->subDays($i)->format('Y-m-d');
//Lấy thời gian hiện tại.
//subDays($i): Trừ đi $i ngày từ thời điểm hiện tại. Ví dụ: nếu $i = 0, sẽ lấy ngày hôm nay, nếu $i = 1, sẽ lấy ngày hôm qua, và cứ tiếp tục như vậy.
                $dateLabels[] = now()->subDays($i)->format('d/m');
                $timeData[] = $testAttempts->filter(function ($attempt) use ($date) {
                    return $attempt->created_at->format('Y-m-d') == $date;
//trả về danh sách bài kiểm tra đã làm cho format giống với date 
                })->count();
            }

            // Đảo ngược mảng để hiển thị theo thứ tự tăng dần
            $stats['dateLabels'] = array_reverse($dateLabels);
            $stats['timeData'] = array_reverse($timeData);
        }

        return view('admin.tests.statistics', compact('test', 'testAttempts', 'stats'));
    }

    /**
     * Kích hoạt/Vô hiệu hóa bài kiểm tra
     */
    public function toggle($id)
    {
        $test = Test::findOrFail($id);

        // Đảo ngược trạng thái
        $test->is_active = !$test->is_active;
//Dòng này sử dụng toán tử ! để đảo ngược giá trị của thuộc tính is_active. Nếu is_active đang là true, nó sẽ chuyển thành false, và nếu đang là false, nó sẽ chuyển thành true.
        $test->save();

        $status = $test->is_active ? 'kích hoạt' : 'vô hiệu hóa';

        return redirect()->route('admin.tests.show', $test->id)
            ->with('success', "Bài kiểm tra đã được $status thành công!");
    }
}
