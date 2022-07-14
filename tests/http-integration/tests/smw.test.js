const { assert } = require("chai");
const { edit, htmlTextOf, showProperty } = require("./wiki");

describe('SMW', async function() {

    before(async function () {
      console.log("** before");
      await edit("Property:Title", "My type: [[Has type::Text]]");
    });

    it("Title property has type Text", async function () {
      text = await htmlTextOf("Property:Title");
      assert.include(
        text,
        '<a href="/index.php/Special:Types/Text" title="Special:Types/Text">Text</a>'
      );
    });

    it("should update inline queries between edit and query X", async function () {
      await assertUpdatedWith("X");
    });

    it("should update inline queries between edit and query Y", async function () {
      await assertUpdatedWith("Y");
    });

    it("should update inline queries between edit and query Z", async function () {
      await assertUpdatedWith("Z");
    });

    it("should update inline queries between edit and query X, Y, Z", async function () {
      await assertUpdatedWith("X");
      await assertUpdatedWith("Y");
      await assertUpdatedWith("Z");
    });

    async function assertUpdatedWith(prefix) {
      console.log(prefix);
      const text = await htmlTextAfterEdit("A", "[[Title::" + prefix + "A| ]]My title: {{#show: {{FULLPAGENAME}}|?Title}}");
      console.log('ask Title: ' + await showProperty('A', 'Title'));
      assert.include(text, "My title: " + prefix + "A");
    }

    async function htmlTextAfterEdit(page, text) {
      await edit(page, text);
      return await htmlTextOf(page);
    }

});
