<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background-color: white; /* Ensure the iframe has a white background */
        }
    </style>
</head>
<body>
    <!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kreslící výzva</title>
<style>
body {
    font-family: Arial, sans-serif;
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    background-color: #f0f8ff;
}
.container {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
}
h1 { color: #2c3e50; }
.setup {
    margin-bottom: 30px;
    text-align: center;
}
.player-inputs {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    justify-content: center;
    margin: 20px 0;
}
.player-input {
    padding: 8px;
    width: 150px;
    border: 1px solid #3498db;
    border-radius: 5px;
}
.category-select {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    justify-content: center;
    margin: 20px 0;
}
button {
    background-color: #3498db;
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    transition: background 0.3s;
}
button:disabled {
    background-color: #bdc3c7;
    cursor: not-allowed;
}
#game-section {
    text-align: center;
}
.hidden { display: none; }
#scoreboard {
    margin: 30px 0;
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    justify-content: center;
}
.player-score {
    background: #ecf0f1;
    padding: 15px;
    border-radius: 10px;
    min-width: 150px;
    transition: transform 0.2s;
}
.player-score.leader {
    background: #f1c40f;
}
.player-score:hover {
    transform: scale(1.05);
}
#result {
    margin: 20px 0;
    font-size: 24px;
    font-weight: bold;
    color: #e74c3c;
}
.controls {
    margin: 20px 0;
}
#current-player {
    font-size: 20px;
    color: #27ae60;
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    display: inline-block;
    margin: 15px 0;
}
.guess-select {
    margin: 20px 0;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
}
.guess-option {
    padding: 8px 15px;
    background: #3498db;
    color: white;
    border-radius: 5px;
    cursor: pointer;
    transition: background 0.3s;
}
.guess-option.selected {
    background: #1a4e7d;
}
.guess-option:hover {
    background: #2980b9;
}
#regenerate-btn {
    margin-top: 15px;
    background: #e67e22;
}
</style>
</head>
<body>
<div class="container">
<h1>Kreslící výzva - Pictionary</h1>

<!-- Setup section -->
<div class="setup" id="setup-section">
    <div class="player-inputs" id="player-inputs">
        <input type="text" class="player-input" placeholder="Jméno hráče 1">
        <input type="text" class="player-input" placeholder="Jméno hráče 2">
    </div>
    <button onclick="togglePlayerInputs('+')">+ Přidat hráče</button>
    <button onclick="togglePlayerInputs('-')">- Odebrat hráče</button>
    <button onclick="startGame()" class="start-btn">Spustit hru</button>
</div>

<!-- Game section -->
<div id="game-section" class="hidden">
    <div id="scoreboard"></div>
    <div class="category-select" id="game-categories"></div>
    <div id="current-player"></div>
    <button onclick="generateItem()" id="generate-btn">Vygenerovat výzvu</button>
    <button onclick="regenerateItem()" id="regenerate-btn" class="hidden">Vygenerovat novou výzvu</button>
    <div id="result"></div>
    <div class="controls">
        <div class="guess-select" id="guess-select"></div>
        <button onclick="confirmGuess()" id="confirm-btn" disabled>Potvrdit výběr</button>
        <!-- Nové tlačítko pro případ, kdy nikdo neuhodl -->
        <button onclick="skipGuess()" id="skip-btn" disabled>Nikdo neuhodl</button>
    </div>
    <div id="round-counter"></div>
    <button onclick="resetGame()" class="reset-btn">Restartovat hru</button>
</div>
</div>

<script>
const categories = {
    zvířata: ["lev", "slon", "medvěd", "žirafa", "tygr", "panda", "krokodýl", "velryba", "ptakopysk", "oktopus", "liška", "vlk", "ještěr", "sov", "žába", "korál", "medúza", "lenochod", "surikata", "pavouk", "mlok", "klokan", "nosorožec", "tapír", "luskoun", "kajman", "puma", "rys", "bizon", "lemur", "hroch", "zebra", "antilopa", "kobyla", "albatros", "kondor"],
    jídlo: ["piza", "sushi", "hamburger", "taco", "ramen", "lasagne", "smoothie", "bageta", "cupcake", "sendvič", "borscht", "fondue", "gnocchi", "risotto", "sashimi", "burrito", "croissant", "donut", "falafel", "gelato", "guacamole", "karamel", "muffin", "nachos", "obložený chléb", "pancake", "quesadilla", "ratatouille", "strudel", "tiramisu", "vafle", "zavináč", "štrúdl", "česnečka", "řízek", "směs ovoce"],
    fantazie: ["drak", "jednorožec", "fénix", "kraken", "golem", "elf", "trpaslík", "kentaur", "válečník", "čaroděj", "mimozemšťan", "android", "válečná loď", "kouzelný prsten", "letící ostrov", "tajemný portál", "zrcadlový svět", "ledový palác", "ohnivý démon", "vodní víla", "duch lesa", "kostlivý pirát", "mechanický pták", "hvězdná brána", "časový stroj", "gravitační bublina", "měsíční zámek", "duhový most", "stínový lov", "kouzelný vějíř", "magnetická bouře", "zrcadlový bludiště", "létající koberec", "tajemná kniha", "kouzelná hůl", "fázový štít"],
    věci: ["hodiny", "kamera", "klobouk", "boty", "kufřík", "svíčka", "kompas", "teleskop", "závěs", "zápisník", "klíč", "zástrčka", "světlomet", "kotva", "přívěs", "lyže", "skateboard", "kolečkový židle", "lodní kormidlo", "paraple", "kufřík", "rybářský prut", "lyže", "stavebnice", "závěsná lampa", "starožitný telefon", "kapesní hodinky", "papírový drak", "závěs s motivem", "stará mapa", "zápisník s kódem", "záhadný klíč", "starožitná kniha", "závěsná soška", "kolečkové brusle", "zářivka"],
    místa: ["horská chata", "náměstí", "letiště", "kino", "zoo", "nemocnice", "škola", "pláž", "stanice metra", "nádraží", "kostel", "knihovna", "supermarket", "kavárna", "lunapark", "stadium", "muzeum", "aquapark", "autobusová zastávka", "stanice plynového čerpadla", "vesmírná stanice", "jezdecký areál", "koupaliště", "tržnice", "stanice lanovky", "vědecká laboratoř", "zábavní park", "národní park", "botanická zahrada", "přístav", "rybářský přístav", "letní tábor", "zámecká zahrada", "vodopád", "jeskyně", "rozhledna", "stanice horské služby"],
    stát: ["Japonsko", "Kanada", "Brazílie", "Egypt", "Austrálie", "Island", "Mexiko", "Indie", "Itálie", "Německo", "Francie", "Čína", "Rusko", "Švédsko", "Norsko", "Švýcarsko", "Rakousko", "Thajsko", "Vietnam", "Argentina", "Chile", "Peru", "Kolumbie", "Maroko", "Kenya", "Jihoafrická republika", "Nový Zéland", "Finsko", "Dánsko", "Holandsko", "Belgie", "Řecko", "Turecko", "Indonésie", "Malajsie", "Singapur", "Filipíny"],
    sport: ["fotbal", "basketbal", "tenis", "plavání", "lyžování", "cyklistika", "volejbal", "hokej", "badminton", "box", "skoky do vody", "snowboarding", "surfing", "golf", "atletika", "gymnastika", "sjezdové lyžování", "rugby", "americký fotbal", "vážení", "kanoistika", "stolní tenis", "squash", "trampolína", "kiteboarding", "parkour", "ultimate frisbee", "bouldering", "kitesurfing", "paddleboarding", "skateboarding", "waterpolo", "biatlon", "curling", "sportovní střelba", "šerm", "triatlon"],
    film: ["Titanic", "Matrix", "Harry Potter", "Avatar", "Piráti z Karibiku", "Jurassic Park", "Toy Story", "Zrození", "Up", "Ledové království", "Shrek", "Pán prstenů", "Hobit", "Star Wars", "Avengers", "Spider-Man", "Batman", "Superman", "X-Men", "Čarodějky z Eastwicku", "Kmotr", "Forrest Gump", "Gladiator", "Braveheart", "Přelet nad kukaččím hnízdem", "Psycho", "Terminátor", "Rambo", "Rocky", "Indiana Jones", "Návrat do budoucnosti", "Blade Runner", "Alien", "Predator", "Ghostbusters"],
    emoce: ["radost", "smutek", "strach", "naděje", "překvapení", "zlost", "láska", "zklamání", "nadšení", "úzkost", "nostalgie", "vděčnost", "zmatek", "údiv", "rozpak", "netrpělivost", "soucit", "pocit viny", "hrdost", "ostych", "nezájem", "naděje", "podezření", "uspokojení", "napětí", "závist", "úcta", "pocit méněcennosti", "odhodlání", "rozpolcenost", "zahanbení", "okouzlení", "netečnost", "pocit osamělosti", "pocit svobody", "pocit bezpečí"],
    profese: ["kuchař", "lékař", "hasič", "učitel", "pilot", "zahradník", "architekt", "hudebník", "sportovec", "herec", "novinář", "programátor", "designer", "fotograf", "řidič", "prodavač", "účetní", "advokát", "inženýr", "vědec", "astronaut", "kadeřník", "číšník", "policista", "zubař", "veterinář", "psycholog", "farmaceut", "diplomat", "historik", "archeolog", "biolog", "chemik", "fyzik", "matematik", "ekonom", "sociolog"],
    historie: ["dinosaurus", "středověký rytíř", "starověký Řím", "vikingská loď", "egyptská pyramida", "římská helma", "válečný tank", "starověká Čína", "řecká socha", "mayský chrám", "aztecký kalendář", "indiánský tipi", "japonský samuraj", "římský koloseum", "středověký hrad", "věž Eiffelova", "starověká Mezopotámie", "římská měna", "vikingský přilba", "egyptský sfing", "řecký chrám", "čínská zeď", "indický taj mahal", "římský aquadukt", "středověká katedrála", "věž Big Ben", "starověká Persie", "římský triumfální oblouk", "vikingský lodě", "egyptský papyrus", "řecká amfora", "středověký trh", "římský senát", "vikingský meč"],
    technologie: ["robot", "raketa", "solární panel", "3D tiskárna", "dron", "virtuální realita", "satelit", "elektrické auto", "chytrý telefon", "notebook", "tablet", "chytré hodinky", "bezdrátové sluchátko", "digitální fotoaparát", "projektor", "externí disk", "powerbanka", "robotický vysavač", "chytré zrcadlo", "hlasový asistent", "bezpečnostní kamera", "termální kamera", "360° fotoaparát", "laserový skener", "3D brýle", "elektronická kniha", "chytré zavlažování", "solární nabíječka", "robotická ruka", "holografický projektor", "nanodron", "kvantový počítač", "biometrický snímač", "větrná elektrárna", "vodíkový motor", "plazmový řez"],
    testovací_kategorie: ["aaa", "bbb", "ccc", "ddd", "eee", "fff", "ggg", "hhh", "iii", "jjj"]
};

let players = [];
let currentPlayerIndex = 0;
let round = 1;
let usedItems = {};
let currentCategory = null;
let itemGenerated = false;
let regenerationUsed = false;

function togglePlayerInputs(action) {
    const inputs = document.querySelectorAll('.player-input');
    const count = inputs.length;

    if(action === '+' && count < 5) {
        const newInput = document.createElement('input');
        newInput.type = 'text';
        newInput.className = 'player-input';
        newInput.placeholder = `Jméno hráče ${count + 1}`;
        document.getElementById('player-inputs').appendChild(newInput);
    }
    if(action === '-' && count > 2) {
        document.getElementById('player-inputs').removeChild(inputs[inputs.length - 1]);
    }
}

function startGame() {
    const inputs = document.querySelectorAll('.player-input');
    players = Array.from(inputs).map(input => input.value.trim()).filter(name => name !== "");

    if(players.length < 2 || players.length > 5) {
        alert("Musí být 2-5 hráčů!");
        return;
    }

    // Initialize scoreboard
    const scoreboard = document.getElementById('scoreboard');
    scoreboard.innerHTML = players.map(name => `
    <div class="player-score">
        <div class="name">${name}</div>
        <div class="points">0</div>
    </div>
    `).join('');

    // Initialize categories
    const gameCategories = document.getElementById('game-categories');
    gameCategories.innerHTML = Object.keys(categories).map(cat => `
    <label><input type="radio" name="category" value="${cat}"> ${cat.replace(/_/g, ' ')}</label>
    `).join('');

    // Select first category by default
    if(gameCategories.querySelector('input[name="category"]')) {
        gameCategories.querySelector('input[name="category"]').checked = true;
    }

    // Show game section
    document.getElementById('setup-section').classList.add('hidden');
    document.getElementById('game-section').classList.remove('hidden');
    updateCurrentPlayer();
    updateRoundCounter();
    resetGuessSelection();
    updateLeaderHighlight();
}

function resetGame() {
    if(!confirm("Opravdu chcete restartovat hru?")) return;

    // Reset all game states
    players = [];
    currentPlayerIndex = 0;
    round = 1;
    usedItems = {};
    currentCategory = null;
    itemGenerated = false;
    regenerationUsed = false;

    // Clear UI
    document.getElementById('scoreboard').innerHTML = '';
    document.getElementById('game-categories').innerHTML = '';
    document.getElementById('current-player').textContent = '';
    document.getElementById('result').textContent = '';
    document.getElementById('round-counter').textContent = '';
    resetGuessSelection();
    document.getElementById('regenerate-btn').classList.add('hidden');

    // Show setup section
    document.getElementById('game-section').classList.add('hidden');
    document.getElementById('setup-section').classList.remove('hidden');

    // Reset player inputs
    const inputs = document.querySelectorAll('.player-input');
    inputs.forEach(input => input.value = "");
    while(inputs.length > 2) {
        document.getElementById('player-inputs').removeChild(inputs[inputs.length - 1]);
    }
}

function updateCurrentPlayer() {
    const currentPlayerDiv = document.getElementById('current-player');
    currentPlayerDiv.textContent = `Na řadě: ${players[currentPlayerIndex]}`;
    currentPlayerDiv.style.backgroundColor = '#f8f9fa';
    currentPlayerDiv.style.padding = '15px';
    currentPlayerDiv.style.borderRadius = '8px';
    currentPlayerDiv.style.fontSize = '20px';
    currentPlayerDiv.style.color = '#27ae60';
}

function generateItem() {
    if(itemGenerated && !regenerationUsed) {
        alert("Pro tento kolečko již bylo vygenerováno slovo!");
        return;
    }

    currentCategory = document.querySelector('input[name="category"]:checked')?.value;
    if(!currentCategory) {
        alert("Vyberte kategorii!");
        return;
    }

    let availableItems = categories[currentCategory].filter(item => !usedItems[currentCategory]?.includes(item));

    if(availableItems.length === 0) {
        alert("Všechny výzvy v této kategorii byly použity!");
        return;
    }

    const randomIndex = Math.floor(Math.random() * availableItems.length);
    const selectedItem = availableItems[randomIndex];

    document.getElementById('result').textContent = selectedItem;
    document.getElementById('generate-btn').disabled = true;
    document.getElementById('skip-btn').disabled = false;

    if(!usedItems[currentCategory]) usedItems[currentCategory] = [];
    usedItems[currentCategory].push(selectedItem);

    // Enable guess selection
    createGuessSelection();
    itemGenerated = true;
    regenerationUsed = false;

    // Zobrazit tlačítko pro regeneraci (pokud chcete)
    // document.getElementById('regenerate-btn').classList.remove('hidden');
}

function regenerateItem() {
    if(regenerationUsed) {
        alert("Novou výzvu můžete vygenerovat pouze jednou za kolo!");
        return;
    }

    // Generate new item without removing the previous one from usedItems
    generateItem();
    regenerationUsed = true;
    document.getElementById('regenerate-btn').disabled = true;
}

/*
function createGuessSelection() {
    const guessSelect = document.getElementById('guess-select');
    guessSelect.innerHTML = players.map((player, index) => `
    <div class="guess-option" onclick="selectGuesser(${index})">${player}</div>
    `).join('');
}
*/

function createGuessSelection() {
    const guessSelect = document.getElementById('guess-select');
    guessSelect.innerHTML = `<p style="flex-basis: 100%; text-align: center;">Kdo to uhodl?</p>` + players.map((player, index) => `
        <div class="guess-option" onclick="selectGuesser(${index})">${player}</div>
    `).join('');
}

let selectedGuesser = null;

function selectGuesser(index) {
    // Remove previous selection
    document.querySelectorAll('.guess-option').forEach(option => {
        option.classList.remove('selected');
    });

    // Add selection to clicked option
    document.querySelectorAll('.guess-option')[index].classList.add('selected');
    selectedGuesser = index;
    document.getElementById('confirm-btn').disabled = false;
    document.getElementById('skip-btn').disabled = false;
}

function confirmGuess() {
    if(selectedGuesser === null || !itemGenerated) return;

    // Award points
    const drawerIndex = currentPlayerIndex;
    const guesserIndex = selectedGuesser;

    // Drawer always gets a point
    updateScore(drawerIndex);

    // If guesser is different, award them too
    if(guesserIndex !== drawerIndex) {
        updateScore(guesserIndex);
    }

    // Prepare for next round
    currentPlayerIndex = (currentPlayerIndex + 1) % players.length;
    if(currentPlayerIndex === 0) round++;

    // Update UI
    updateCurrentPlayer();
    updateRoundCounter();
    resetGuessSelection();
    document.getElementById('result').textContent = '';
    document.getElementById('generate-btn').disabled = false;
    document.getElementById('regenerate-btn').classList.add('hidden');
    document.getElementById('regenerate-btn').disabled = false;
    itemGenerated = false;
    regenerationUsed = false;
    updateLeaderHighlight();
}

// Nová funkce pro případ, kdy nikdo neuhodl
function skipGuess() {
    if(!itemGenerated) return;

    // Přeskočí aktuální kolo bez udělení bodů

    // Přepne na dalšího hráče
    currentPlayerIndex = (currentPlayerIndex + 1) % players.length;
    if(currentPlayerIndex === 0) round++;

    // Aktualizace UI
    updateCurrentPlayer();
    updateRoundCounter();
    resetGuessSelection();
    document.getElementById('result').textContent = '';
    document.getElementById('generate-btn').disabled = false;
    document.getElementById('skip-btn').disabled = true;
    document.getElementById('regenerate-btn').classList.add('hidden');
    document.getElementById('regenerate-btn').disabled = false;
    itemGenerated = false;
    regenerationUsed = false;
    updateLeaderHighlight();
}

function updateScore(playerIndex) {
    const scoreElement = document.querySelectorAll('.player-score')[playerIndex].querySelector('.points');
    scoreElement.textContent = parseInt(scoreElement.textContent) + 1;
    updateLeaderHighlight();
}

function resetGuessSelection() {
    selectedGuesser = null;
    document.getElementById('guess-select').innerHTML = '';
    document.getElementById('confirm-btn').disabled = true;
    document.getElementById('skip-btn').disabled = true;
}

function updateRoundCounter() {
    document.getElementById('round-counter').textContent = `Kolo: ${round}`;
}

function updateLeaderHighlight() {
    const scoreElements = document.querySelectorAll('.player-score');
    const scores = Array.from(scoreElements).map(el => parseInt(el.querySelector('.points').textContent));
    const maxScore = Math.max(...scores);
    const leaders = scores.filter(score => score === maxScore);

    scoreElements.forEach((el, index) => {
        if(scores[index] === maxScore && leaders.length === 1) {
            el.classList.add('leader');
        } else {
            el.classList.remove('leader');
        }
    });
}
</script>
</body>
</html>
