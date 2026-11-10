function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function loadHome() {
    let frameLoading = document.querySelector(".loading");
    let textLoading = document.querySelector(".loading-text");

    await sleep(500);
    textLoading.textContent = "";
    frameLoading.classList.remove("active");
    await sleep(300);
    await loadHomeHeader();
}

async function loadHomeHeader() {
    let frameHeader = document.querySelector(".container-header");
    frameHeader.classList.add("active");
}
